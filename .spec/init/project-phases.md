# Agendamento — Project Phases

<!-- inputs: project-description.md@sha256:269de7f9cebe user-stories.md@sha256:54d87a61d07f database-schema.md@sha256:8908296e5bb8 -->

## Overview

O build segue a ordem **fundação primeiro**:
1. **Phase 1:** ambiente, autenticação e banco de testes PostgreSQL.
2. **Phase 2:** todas as migrations, com as constraints de integridade (FKs compostas, `CHECK`, exclusion constraint) e seus testes diretos no banco.
3. **Phase 3:** modelos com todos os relacionamentos, enum `BookingStatus` e factories coerentes.

Só depois vêm os fluxos:
4. **Phase 4:** endpoint `POST /api/bookings`, com validação, isolamento de tenant e cálculo do término.
5. **Phase 5:** conflito de horários e testes de concorrência real, na Action e via HTTP.
6. **Phase 6:** seeder de demonstração restrito ao ambiente `local`.
7. **Phase 7:** qualidade e documentação.

O projeto é **somente API**. Não existe frontend nem `.spec/init/design/`, então não há fundação de UI nem tarefas de tela. São **7 phases**. A **linha de corte do MVP é a Phase 5**: ela fecha RF01–RF06 e todas as histórias High. As Phases 6 e 7 completam o entregável de estudo (US-4.1, de prioridade Medium, e o acabamento). Cada `## Phase N:`, com seus `### Phase N.M:`, é dimensionada para uma sessão de agente e é referenciada por número.

**Conventions:**
- `[ ]` pending · `[x]` done in the codebase.
- Phases and sub-phases are numbered (`Phase 1`, `Phase 5.3`) for reference by AI agents.
- Business-logic tasks list the **feature tests** to generate; frontend-only tasks list validatable **acceptance criteria** and a **Design ref**.
- Testes usam PostgreSQL real (`agendamento_testing`), nunca SQLite, porque a exclusion constraint, as FKs compostas e os SQLSTATEs (`23P01`, `23503`, `23514`, `23502`) são específicos do PostgreSQL.
- Testes que dependem de "agora" congelam o relógio em `2026-01-01T12:00:00Z` (`Tests\TestCase::FROZEN_NOW`) e usam datas **relativas** (`now()->addDay()->setTime(14, 0)`), nunca datas absolutas.

---

## Phase 1: Fundação — ferramentas, autenticação e banco de testes

**Goal:** preparar o ambiente para que toda phase seguinte rode testes contra PostgreSQL real, com Sanctum instalado. · **Depends on:** none · **Covers:** Tech Stack, `personal_access_tokens`, US-3.1 (infra), US-2.2 (isolamento do banco de testes), relógio controlado

Contexto do codebase: o projeto é o skeleton Laravel 13.42 (PHP 8.5). Ainda não há `routes/api.php`, Sanctum nem modelos de domínio. O `phpunit.xml` usa SQLite `:memory:`, e o `compose.yaml` sobe só o `postgres:18-alpine` (porta 5435, banco `agendamento`, usuário `agendamento`).

### Phase 1.1: Ferramentas e instalação da API

- [ ] **Task:** Instalar o Laravel Boost conforme as instruções do `CLAUDE.md`/`AGENTS.md` do repositório.
  - **Acceptance criteria:**
    - `composer require laravel/boost --dev` e `php artisan boost:install` executados sem erro.
    - `laravel/boost` aparece em `require-dev` do `composer.json`.
    - `AGENTS.md`/`CLAUDE.md` regenerados com as guidelines do Boost; as phases seguintes seguem essas guidelines.
  - **Traces:** Tech Stack (tooling) · instruções do repositório (`CLAUDE.md`)

- [ ] **Task:** Instalar o Laravel Sanctum e o roteamento de API com `php artisan install:api`.
  - **Acceptance criteria:**
    - `laravel/sanctum` aparece em `require` do `composer.json`, e `config/sanctum.php` existe.
    - `routes/api.php` existe e está registrado em `bootstrap/app.php` (`withRouting(api: ...)`), com prefixo `/api`.
    - Existe a migration padrão da tabela `personal_access_tokens`, com as colunas do schema: `tokenable_type`, `tokenable_id`, `name`, `token` (unique, 64), `abilities`, `last_used_at`, `expires_at` e timestamps.
    - A configuração de JSON para `api/*` já existente em `bootstrap/app.php` é preservada.
  - **Traces:** `personal_access_tokens` · US-3.1 · Tech Stack (Autenticação)

- [x] **Task:** Renderizar exceções como JSON para `api/*` (`401`, `422`, `409` e `500` em JSON).
  - **Acceptance criteria:**
    - `bootstrap/app.php` contém `shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson())`. Já presente no codebase.
  - **Traces:** US-1.2 · US-3.1 · workflow 1

- [x] **Task:** Fuso horário da aplicação em UTC.
  - **Acceptance criteria:**
    - `config/app.php` tem `'timezone' => 'UTC'`, então as datas persistidas e serializadas partem de UTC. Já presente no codebase.
  - **Traces:** US-1.1 (serialização UTC) · Key Concept "Intervalo de horário"

### Phase 1.2: Banco de testes PostgreSQL dedicado

- [ ] **Task:** Provisionar o banco `agendamento_testing` no mesmo PostgreSQL do `compose.yaml`.
  - **Acceptance criteria:**
    - O script `docker/pgsql/create-testing-database.sql` contém `CREATE DATABASE agendamento_testing OWNER agendamento;` e está montado em `/docker-entrypoint-initdb.d/` no serviço `pgsql` do `compose.yaml`.
    - Para volumes já existentes, o comando manual `docker compose exec pgsql createdb -U agendamento agendamento_testing` está documentado. O README é atualizado na Phase 7; até lá, o comando fica em comentário no `compose.yaml`.
    - `docker compose exec pgsql psql -U agendamento -lqt` lista `agendamento` e `agendamento_testing`.
  - **Traces:** US-2.2 (isolamento do banco de testes) · workflow 2

- [ ] **Task:** Apontar o PHPUnit para o PostgreSQL de testes.
  - **Acceptance criteria:**
    - Em `phpunit.xml`, as linhas `DB_CONNECTION=sqlite` e `DB_DATABASE=:memory:` são substituídas por `DB_CONNECTION=pgsql` e `DB_DATABASE=agendamento_testing`. Host, porta, usuário e senha vêm do `.env` (`127.0.0.1:5435`).
    - `.env.example` documenta `DB_TEST_DATABASE=agendamento_testing` como referência.
    - `php artisan test` roda contra `agendamento_testing` e nunca toca o banco `agendamento`.
  - **Feature tests:**
    - `TestDatabaseConfigurationTest::test_tests_run_on_dedicated_postgres_database` → `DB::connection()->getDriverName() === 'pgsql'` e `DB::connection()->getDatabaseName() === 'agendamento_testing'`.
  - **Traces:** US-2.2 · workflow 2 (isolamento do banco de testes) · Tech Stack (Testes)

- [ ] **Task:** Convenção de relógio controlado na base de testes.
  - **Acceptance criteria:**
    - `Tests\TestCase` declara `public const FROZEN_NOW = '2026-01-01T12:00:00Z';` e o método `freezeClock(): void`, que chama `$this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW))`.
    - Testes que dependem de "agora" chamam `freezeClock()` no `setUp` e constroem datas relativas a `now()`.
    - Nenhum teste de negócio usa data absoluta como `starts_at`.
  - **Feature tests:**
    - `ClockConventionTest::test_freeze_clock_pins_now` → após `freezeClock()`, `now()->toIso8601ZuluString() === '2026-01-01T12:00:00Z'`.
  - **Traces:** US-1.2 · US-1.3 · US-2.1 · Key Concept "Relógio controlado nos testes"

- [ ] **Task:** Proteção contra comandos destrutivos fora do banco de testes.
  - **Acceptance criteria:**
    - `Tests\TestCase` implementa o hook `beforeRefreshingDatabase()` do `RefreshDatabase`, que roda **antes** de qualquer `migrate:fresh`. O hook chama `assertSafeTestDatabase()`, que lança `RuntimeException` e aborta o teste se uma destas condições valer:
      - `app()->environment() !== 'testing'`;
      - `DB::connection()->getDriverName() !== 'pgsql'`;
      - `DB::connection()->getDatabaseName() !== 'agendamento_testing'`.
    - `assertSafeTestDatabase()` é `public static` e reutilizável. A orquestração de concorrência (Phase 5.2 e 5.3) a chama antes do `migrate`, de criar dados e do `tearDown`. O worker e o servidor HTTP de teste verificam `DB_DATABASE === 'agendamento_testing'` antes de qualquer escrita e saem com `{"result":"error","message":"unsafe database"}` caso contrário.
    - `AppServiceProvider::boot()` chama `DB::prohibitDestructiveCommands(app()->isProduction())`, bloqueando `migrate:fresh`, `db:wipe` e afins em produção.
  - **Feature tests:**
    - `SafeTestDatabaseTest::test_guard_rejects_non_testing_database` → com `config(['database.connections.pgsql.database' => 'agendamento'])` e `DB::purge()`, `assertSafeTestDatabase()` lança `RuntimeException`. O teste restaura a config no `finally` e **não** usa `RefreshDatabase`.
    - `SafeTestDatabaseTest::test_guard_accepts_agendamento_testing`.
  - **Traces:** US-2.2 (isolamento do banco de testes) · US-5.1 · workflow 2

---

## Phase 2: Fundação do banco — migrations, constraints e integridade

**Goal:** criar todo o schema PostgreSQL com as invariantes impostas pelo banco e prová-las com testes diretos (query builder, sem Form Request). · **Depends on:** Phase 1 · **Covers:** `booking_statuses`, `companies`, `users`, `professionals`, `customers`, `services`, `professional_service`, `bookings` · US-5.1 · US-2.2 (insert direto) · workflow 4

Regras desta phase:
- Constraints que o Blueprint não expressa (extensão, `CHECK`, `EXCLUDE`) são criadas com `DB::statement` e removidas no `down()`.
- Toda tabela de tenant tem `UNIQUE (company_id, id)` como alvo das FKs compostas.
- **Transação abortada no PostgreSQL.** O `RefreshDatabase` envolve cada teste numa transação. No PostgreSQL, um comando que falha deixa essa transação em estado de erro, e qualquer consulta seguinte recebe `25P02` (`in_failed_sql_transaction`). Por isso, **toda operação que deve falhar roda dentro de um savepoint**: `DB::transaction(fn () => ...)` aninhado vira `SAVEPOINT`, e o Laravel faz `ROLLBACK TO SAVEPOINT` quando há exceção. Nunca se captura a exceção de um insert executado diretamente na transação externa.
- Os testes da Phase 2.4 usam `RefreshDatabase` e inserem com `DB::table(...)`, porque os modelos e factories só chegam na Phase 3. Ficam num helper próprio: `Tests\Support\RawFixtures`, com métodos `company()`, `professional($companyId)`, `customer($companyId)`, `service($companyId, $minutes)`, `offer($companyId, $professionalId, $serviceId)` e `booking([...])`.

### Phase 2.1: Extensão, lookup de status e tenant

- [ ] **Task:** Migration que habilita a extensão `btree_gist`. Deve ser a primeira migration de domínio.
  - **Acceptance criteria:**
    - `up()` executa `CREATE EXTENSION IF NOT EXISTS btree_gist`; `down()` executa `DROP EXTENSION IF EXISTS btree_gist`.
    - Após `migrate`, `SELECT 1 FROM pg_extension WHERE extname = 'btree_gist'` retorna uma linha em `agendamento` e em `agendamento_testing`.
  - **Traces:** US-2.1 · US-2.2 · Key Concept "Exclusion constraint"

- [ ] **Task:** Migration de `booking_statuses`, com seed dos IDs fixos dentro da própria migration.
  - **Acceptance criteria:**
    - Colunas:
      - `id bigint` PK, **sem** autoincremento;
      - `name varchar not null`;
      - `slug varchar unique not null`;
      - `description text null`;
      - timestamps.
    - A migration insere exatamente `(1, 'Confirmado', 'confirmed')` e `(2, 'Cancelado', 'cancelled')`.
    - Após `migrate:fresh` (e em todo teste com `RefreshDatabase`), a tabela tem exatamente 2 linhas, sem depender de `db:seed`.
  - **Feature tests:**
    - `BookingStatusesSeedTest::test_fixed_status_ids_exist_after_migration` → `DB::table('booking_statuses')->orderBy('id')->get(['id', 'slug'])` retorna `[1 => confirmed, 2 => cancelled]`.
  - **Traces:** `booking_statuses` · US-5.1 · Key Concept "Status do Booking"

- [ ] **Task:** Migration de `companies`.
  - **Acceptance criteria:**
    - Colunas: `id` (bigint autoincremental), `name varchar not null` e timestamps.
  - **Traces:** `companies` · US-3.2 · Key Concept "Company"

- [ ] **Task:** Migration `add_company_id_to_users_table`, executada depois de `companies`, sem alterar a migration skeleton de `users`.
  - **Acceptance criteria:**
    - `users.company_id` é `bigint not null`, com FK para `companies.id` e índice.
    - As tabelas skeleton (`password_reset_tokens`, `sessions`, `cache`, `jobs`) permanecem intactas.
    - `down()` remove a FK e a coluna.
  - **Traces:** `users` · US-3.2 · Key Concept "User"

### Phase 2.2: Cadastros do tenant e pivô

- [ ] **Task:** Migration de `professionals`.
  - **Acceptance criteria:**
    - Colunas: `id`, `company_id` (FK `companies.id`, not null), `name varchar not null` e timestamps.
    - `UNIQUE (company_id, id)` com o nome `professionals_company_id_id_unique`.
  - **Traces:** `professionals` · US-3.2 · US-5.1

- [ ] **Task:** Migration de `customers`.
  - **Acceptance criteria:**
    - Colunas: `id`, `company_id` (FK, not null), `name varchar not null`, `email varchar null`, `phone varchar null` e timestamps.
    - `UNIQUE (company_id, id)` com o nome `customers_company_id_id_unique`.
  - **Traces:** `customers` · US-3.2 · US-5.1

- [ ] **Task:** Migration de `services`, com a constraint `CHECK` de duração.
  - **Acceptance criteria:**
    - Colunas: `id`, `company_id` (FK, not null), `name varchar not null`, `duration_minutes integer not null` e timestamps.
    - `UNIQUE (company_id, id)` com o nome `services_company_id_id_unique`.
    - Constraint `services_duration_positive`: `CHECK (duration_minutes > 0)`.
  - **Traces:** `services` · US-1.3 · US-5.1

- [ ] **Task:** Migration da pivô `professional_service`, com FKs compostas.
  - **Acceptance criteria:**
    - Colunas `company_id`, `professional_id` e `service_id` (todas `bigint not null`), mais timestamps.
    - PK `(professional_id, service_id)`.
    - `UNIQUE (company_id, professional_id, service_id)` com o nome `professional_service_company_professional_service_unique`.
    - Índice em `service_id`.
    - FK `(company_id, professional_id)` → `professionals (company_id, id)`, `ON DELETE CASCADE`.
    - FK `(company_id, service_id)` → `services (company_id, id)`, `ON DELETE CASCADE`.
  - **Traces:** `professional_service` · US-1.3 · US-3.2 · US-5.1 · Key Concept "Professional ↔ Service"

### Phase 2.3: Bookings e exclusion constraint

- [ ] **Task:** Migration de `bookings`, com FKs compostas e `CHECK` de intervalo.
  - **Acceptance criteria:**
    - Colunas:
      - `id`, `company_id` (FK `companies.id`), `professional_id`, `customer_id`, `service_id`;
      - `status_id` (FK `booking_statuses.id`, `default 1`);
      - `starts_at timestamptz` e `ends_at timestamptz` (`$table->timestampTz()`);
      - timestamps.
    - Todas as colunas de negócio são `NOT NULL`.
    - FK `(company_id, customer_id)` → `customers (company_id, id)`, `ON DELETE RESTRICT`.
    - FK `(company_id, professional_id, service_id)` → `professional_service (company_id, professional_id, service_id)`, `ON DELETE RESTRICT`.
    - Não há FK simples própria em `professional_id`/`service_id`; a FK composta cobre ambos.
    - Constraint `bookings_ends_after_starts`: `CHECK (ends_at > starts_at)`.
    - Índices `(company_id, customer_id)`, `(company_id, professional_id, service_id)` e `(professional_id, starts_at)`.
  - **Traces:** `bookings` · US-1.1 · US-1.3 · US-3.2 · US-5.1

- [ ] **Task:** Criar a exclusion constraint `bookings_no_overlap` na mesma migration de `bookings` ou numa subsequente.
  - **Acceptance criteria:**
    - `up()` executa `ALTER TABLE bookings ADD CONSTRAINT bookings_no_overlap EXCLUDE USING gist (professional_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE (status_id = 1)`.
    - `down()` remove a constraint.
    - `\d bookings` (ou uma consulta a `pg_constraint`) mostra a constraint do tipo `x`.
  - **Traces:** `bookings` · US-2.1 · US-2.2 · Key Concept "Exclusion constraint"

### Phase 2.4: Testes de integridade no banco

- [ ] **Task:** Helper `Tests\Support\RawFixtures` para inserts diretos, sem modelos, e uma asserção de SQLSTATE.
  - **Acceptance criteria:**
    - O helper cria company, professional, customer, service e o vínculo na pivô via `DB::table()->insertGetId()`. Também cria bookings com valores arbitrários, inclusive inválidos.
    - Existe a asserção `assertSqlState(string $state, Closure $operation)`.
      - Ela executa `$operation` **dentro de `DB::transaction()`**, que vira savepoint sob o `RefreshDatabase`.
      - Captura a `QueryException` já depois do `ROLLBACK TO SAVEPOINT` e compara `$e->errorInfo[0]` com o estado esperado.
      - Falha se nenhuma exceção ocorrer, ou se o estado for `25P02`.
    - Depois de `assertSqlState`, a transação externa do teste continua utilizável. Toda asserção posterior (`assertDatabaseCount`, novos inserts) funciona.
  - **Feature tests:**
    - `RawFixturesTest::test_queries_still_work_after_expected_constraint_failure` → `assertSqlState('23514', ...)` seguido de `DB::table('companies')->count()` não lança `25P02`.
    - `RawFixturesTest::test_assert_sql_state_fails_when_no_exception_is_thrown`.
  - **Traces:** US-5.1 · workflow 4

- [ ] **Task:** Testes de integridade multi-tenant via FKs compostas.
  - **Acceptance criteria:**
    - Todos os inserts são feitos por `DB::table`, sem Form Request nem modelos.
    - Cada caso falha com `23503`, e nenhuma linha inválida permanece.
  - **Feature tests:**
    - `TenantIntegrityTest::test_booking_with_customer_from_other_company_fails` → `23503`.
    - `TenantIntegrityTest::test_booking_with_professional_from_other_company_fails` → `23503`.
    - `TenantIntegrityTest::test_booking_with_service_from_other_company_fails` → `23503`.
    - `TenantIntegrityTest::test_booking_with_service_not_offered_by_professional_fails` → `23503` (sem linha na pivô).
    - `TenantIntegrityTest::test_pivot_with_professional_and_service_from_different_companies_fails` → `23503`.
    - `TenantIntegrityTest::test_deleting_customer_with_bookings_is_restricted` → `23503`.
    - `TenantIntegrityTest::test_deleting_professional_cascades_pivot_rows` → linhas da pivô removidas quando o profissional não tem bookings.
  - **Traces:** US-5.1 · US-3.2 · workflow 3 · workflow 4 · `professional_service` · `bookings` · `customers`

- [ ] **Task:** Testes de `CHECK`, `NOT NULL` e status no banco.
  - **Acceptance criteria:**
    - Todos os inserts são feitos por `DB::table`, e cada violação retorna o SQLSTATE exato.
  - **Feature tests:**
    - `DatabaseInvariantsTest::test_booking_with_ends_at_equal_to_starts_at_fails` → `23514`.
    - `DatabaseInvariantsTest::test_booking_with_ends_at_before_starts_at_fails` → `23514`.
    - `DatabaseInvariantsTest::test_booking_with_unknown_status_id_fails` → `status_id = 99` dá `23503`.
    - `DatabaseInvariantsTest::test_service_with_zero_or_negative_duration_fails` → `0` e `-1` dão `23514`.
    - `DatabaseInvariantsTest::test_null_business_columns_fail` → `23502`. É um data provider que anula, um de cada vez:
      - em `bookings`: `company_id`, `professional_id`, `customer_id`, `service_id`, `starts_at`, `ends_at`;
      - em `services`: `name`, `duration_minutes`;
      - em `professionals.name` e `customers.name`.
  - **Traces:** US-5.1 · workflow 4 · `bookings` · `services` · `booking_statuses`

- [ ] **Task:** Testes da exclusion constraint por insert direto, contornando a aplicação.
  - **Acceptance criteria:**
    - Todos os horários são relativos a `now()` com o relógio congelado (`freezeClock()`).
  - **Feature tests:**
    - `ExclusionConstraintTest::test_overlapping_confirmed_bookings_same_professional_fail` → segundo insert sobreposto dá `23P01`.
    - `ExclusionConstraintTest::test_adjacent_bookings_are_allowed` → `[14:00,15:00)` e `[15:00,16:00)` coexistem.
    - `ExclusionConstraintTest::test_cancelled_booking_does_not_block_slot` → insert sobreposto a um booking com `status_id = 2` é aceito.
    - `ExclusionConstraintTest::test_overlap_for_different_professionals_is_allowed` → aceito.
    - `ExclusionConstraintTest::test_reconfirming_cancelled_booking_over_confirmed_fails` → `UPDATE status_id = 1` sobre um intervalo ocupado dá `23P01`.
  - **Traces:** US-2.1 · US-2.2 (insert direto) · US-5.1 · workflow 4

---

## Phase 3: Modelos, relacionamentos e factories

**Goal:** criar todos os modelos Eloquent já com relacionamentos completos, o enum `BookingStatus` e factories que geram dados válidos sob todas as constraints. · **Depends on:** Phase 2 · **Covers:** `companies`, `users`, `professionals`, `customers`, `services`, `professional_service`, `bookings`, `booking_statuses`, `personal_access_tokens` · US-5.1 · US-4.1 (factories)

Decisão de modelagem: `booking_statuses` **não ganha modelo Eloquent**. Ela existe para a integridade referencial e para a exclusion constraint. Na aplicação, ela é espelhada pelo enum PHP `App\Enums\BookingStatus: int`, e `Booking::$casts['status_id']` usa esse enum. Um teste garante que enum e tabela não divergem.

### Phase 3.1: Enum e modelos

- [ ] **Task:** Enum `App\Enums\BookingStatus: int`.
  - **Acceptance criteria:**
    - Casos `Confirmed = 1` e `Cancelled = 2`.
    - O método `slug(): string` retorna `'confirmed'` e `'cancelled'`.
    - O enum é usado só na aplicação; não existe coluna enum no banco.
  - **Feature tests:**
    - `BookingStatusEnumTest::test_enum_mirrors_booking_statuses_table` → para cada linha de `booking_statuses`, `BookingStatus::from($row->id)->slug() === $row->slug`, e `count(BookingStatus::cases())` é igual ao total de linhas.
  - **Traces:** US-5.1 · `booking_statuses` · Key Concept "Status do Booking"

- [ ] **Task:** Modelo `Company`.
  - **Acceptance criteria:**
    - Fillable `name`.
    - Relacionamentos `hasMany`: `users()`, `professionals()`, `customers()`, `services()` e `bookings()`.
  - **Traces:** `companies` · Key Concept "Company"

- [ ] **Task:** Atualizar o modelo `User` para o tenant e o Sanctum.
  - **Acceptance criteria:**
    - Usa o trait `Laravel\Sanctum\HasApiTokens`.
    - Fillable inclui `company_id`.
    - Relacionamento `company(): BelongsTo`.
    - Mantém os casts e hidden existentes.
  - **Traces:** `users` · `personal_access_tokens` · US-3.1 · US-3.2

- [ ] **Task:** Modelo `Professional`.
  - **Acceptance criteria:**
    - Fillable `company_id` e `name`.
    - Relacionamentos:
      - `company(): BelongsTo`;
      - `services(): BelongsToMany` via `professional_service`, com `withPivot('company_id')->withTimestamps()`;
      - `bookings(): HasMany`.
    - O método `offer(Service $service): void` grava o vínculo na pivô com `company_id = $this->company_id`. Se as empresas diferirem, o banco rejeita pela FK composta; o método não contorna isso.
  - **Feature tests:**
    - `ProfessionalModelTest::test_offer_attaches_service_with_company_id` → a pivô contém `(company_id, professional_id, service_id)`.
    - `ProfessionalModelTest::test_offer_service_from_other_company_is_rejected_by_database` → `assertSqlState('23503', fn () => $professional->offer($foreignService))`, isolado em savepoint.
  - **Traces:** `professionals` · `professional_service` · US-1.3 · US-3.2

- [ ] **Task:** Modelos `Customer` e `Service`.
  - **Acceptance criteria:**
    - `Customer`:
      - fillable `company_id`, `name`, `email`, `phone`;
      - relacionamentos `company()` e `bookings()`.
    - `Service`:
      - fillable `company_id`, `name`, `duration_minutes`, com cast `integer`;
      - relacionamentos `company()`, `professionals(): BelongsToMany` via `professional_service` com `withPivot('company_id')` e `bookings()`.
  - **Traces:** `customers` · `services` · US-1.3

- [ ] **Task:** Modelo `Booking`.
  - **Acceptance criteria:**
    - Fillable: `company_id`, `professional_id`, `customer_id`, `service_id`, `status_id`, `starts_at`, `ends_at`.
    - Casts:
      - `starts_at` e `ends_at` → `immutable_datetime`;
      - `status_id` → `BookingStatus::class`.
    - Relacionamentos `company()`, `professional()`, `customer()` e `service()` (todos `BelongsTo`).
    - Escopo `scopeConfirmed()` (`status_id = 1`).
    - Datas lidas do banco voltam em UTC.
  - **Feature tests:**
    - `BookingModelTest::test_status_is_cast_to_enum` → `$booking->status_id === BookingStatus::Confirmed`.
    - `BookingModelTest::test_relationships_resolve_to_same_company` → professional, customer e service carregados têm o mesmo `company_id` do booking.
  - **Traces:** `bookings` · US-1.1 · US-5.1

### Phase 3.2: Factories coerentes com as constraints

- [ ] **Task:** `CompanyFactory` e `UserFactory` com tenant.
  - **Acceptance criteria:**
    - `CompanyFactory` gera `name`.
    - `UserFactory` define `company_id => Company::factory()` e mantém senha e e-mail do skeleton.
  - **Traces:** `companies` · `users` · US-4.1

- [ ] **Task:** `ProfessionalFactory`, `CustomerFactory` e `ServiceFactory`.
  - **Acceptance criteria:**
    - Todas definem `company_id => Company::factory()`.
    - `ServiceFactory` gera `duration_minutes` em `[30, 45, 60]` e tem o state `duration(int $minutes)`.
    - `ProfessionalFactory` tem o state `offering(Service ...$services)`, que chama `offer()` após a criação.
  - **Traces:** `professionals` · `customers` · `services` · `professional_service` · US-4.1

- [ ] **Task:** `BookingFactory` coerente.
  - **Acceptance criteria:**
    - Por padrão, cria company, professional, customer e service da **mesma** empresa e garante o vínculo na pivô.
    - `starts_at = now()->addDay()->setTime(14, 0)` (UTC), `ends_at = starts_at + service.duration_minutes` e `status_id = Confirmed`.
    - States:
      - `cancelled()` → `status_id = Cancelled`;
      - `for(Professional, Service, Customer)`;
      - `startingAt(CarbonImmutable)`, que recalcula `ends_at`.
  - **Feature tests:**
    - `FactoriesIntegrityTest::test_booking_factory_satisfies_all_constraints` → `Booking::factory()->count(10)->create()` com profissionais distintos não lança exceção.
    - `FactoriesIntegrityTest::test_booking_factory_computes_ends_at_from_service_duration` → `ends_at->diffInMinutes(starts_at)` é igual a `duration_minutes`.
  - **Traces:** `bookings` · US-1.3 · US-4.1

---

## Phase 4: Criação de agendamento — endpoint, validação, término e tenant

**Goal:** entregar `POST /api/bookings` autenticado, com validação escopada ao tenant, `ends_at` calculado pelo serviço e resposta `201`. O conflito `409` fica para a Phase 5. · **Depends on:** Phase 3 · **Covers:** US-1.1 · US-1.2 · US-1.3 · US-3.1 · US-3.2 · workflow 1 · workflow 3

Contrato (workflow 1):
- **Request:**
  - campos: `professional_id`, `customer_id`, `service_id` (inteiros) e `starts_at` (ISO 8601 com offset);
  - `ends_at` e `company_id` enviados no payload são ignorados (só `validated()` é usado).
- **Response `201`:**
  - `data` com `id`, `professional_id`, `customer_id`, `service_id`, `starts_at`, `ends_at`, `status` (slug) e `created_at`;
  - todas as datas em UTC no formato `Y-m-d\TH:i:s\Z`.
- **Erros:** `401` sem autenticação; `422` no formato padrão do Laravel (`message` + `errors`).

Todos os testes de feature desta phase usam `RefreshDatabase`, `freezeClock()` e `Sanctum::actingAs()` (exceto os de `401`, que usam headers reais).

### Phase 4.1: Rota e autenticação

- [ ] **Task:** Registrar a rota `POST /api/bookings`.
  - **Acceptance criteria:**
    - Em `routes/api.php`: `Route::post('/bookings', [BookingController::class, 'store'])->middleware('auth:sanctum')->name('bookings.store')`.
    - `php artisan route:list --path=api/bookings` mostra o método `POST`, o middleware `auth:sanctum` e o nome `bookings.store`.
  - **Traces:** US-1.1 · US-3.1 · workflow 1

- [ ] **Task:** Testes de autenticação do endpoint.
  - **Acceptance criteria:**
    - Requisições não autenticadas recebem `401` em JSON, e nenhum booking é persistido.
  - **Feature tests:**
    - `BookingAuthenticationTest::test_request_without_authorization_header_returns_401`.
    - `BookingAuthenticationTest::test_request_with_invalid_token_returns_401` → `Bearer` com valor aleatório.
    - `BookingAuthenticationTest::test_request_with_revoked_token_returns_401` → token criado e depois deletado.
    - `BookingAuthenticationTest::test_request_with_expired_token_returns_401` → `expires_at` no passado.
    - `BookingAuthenticationTest::test_valid_token_is_accepted` → token real via `createToken()` retorna `201` com payload válido.
    - Todos os casos `401` verificam `assertDatabaseCount('bookings', 0)`.
  - **Traces:** US-3.1 · workflow 3

### Phase 4.2: Validação e isolamento de tenant (`StoreBookingRequest`)

- [ ] **Task:** Form Request `App\Http\Requests\StoreBookingRequest`.
  - **Acceptance criteria:**
    - Todas as regras usam `bail`. `$companyId = $this->user()->company_id`.
    - `professional_id`: `required|integer|Rule::exists('professionals', 'id')->where('company_id', $companyId)`.
    - `customer_id`: `required|integer|Rule::exists('customers', 'id')->where('company_id', $companyId)`.
    - `service_id`:
      - `required|integer|Rule::exists('services', 'id')->where('company_id', $companyId)`;
      - mais `Rule::exists('professional_service', 'service_id')->where('company_id', $companyId)->where('professional_id', (int) $this->input('professional_id'))`.
    - `starts_at`: `required|date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s\Z|after:now`.
      - Data sem offset é rejeitada (ver Open Questions).
      - Se o PHP não aceitar `Z` no formato `P`, o segundo formato cobre.
    - Um ID de outra empresa produz **a mesma** mensagem de erro que um ID inexistente, porque a mesma regra `exists` escopada falha nos dois casos.
  - **Traces:** US-1.2 · US-1.3 · US-3.2 · workflow 1 · workflow 3

- [ ] **Task:** Testes de validação do payload.
  - **Acceptance criteria:**
    - Toda resposta inválida é `422` com `message` e `errors.<campo>`, e `assertDatabaseCount('bookings', 0)`.
  - **Feature tests:**
    - `StoreBookingValidationTest::test_each_required_field_missing_returns_422` → data provider com `professional_id`, `customer_id`, `service_id` e `starts_at`.
    - `StoreBookingValidationTest::test_non_integer_ids_return_422` → `"abc"` e `1.5` para cada ID.
    - `StoreBookingValidationTest::test_nonexistent_ids_return_422`.
    - `StoreBookingValidationTest::test_invalid_starts_at_format_returns_422` → `"amanhã"`, `"2026-13-01T10:00:00Z"` e data sem offset.
    - `StoreBookingValidationTest::test_starts_at_in_the_past_returns_422` → `now()->subMinute()`.
    - `StoreBookingValidationTest::test_starts_at_just_in_the_future_is_accepted` → `now()->addMinute()` retorna `201`.
    - `StoreBookingValidationTest::test_starts_at_accepts_offset_and_zulu` → `-03:00` e `Z` retornam `201`.
  - **Traces:** US-1.2 · workflow 1

- [ ] **Task:** Testes de isolamento de tenant e de vínculo profissional/serviço.
  - **Acceptance criteria:**
    - Todas as tentativas cross-tenant são indistinguíveis de IDs inexistentes, e nada é persistido.
  - **Feature tests:**
    - `TenantIsolationTest::test_professional_from_other_company_returns_422_on_professional_id`.
    - `TenantIsolationTest::test_customer_from_other_company_returns_422_on_customer_id`.
    - `TenantIsolationTest::test_service_from_other_company_returns_422_on_service_id`.
    - `TenantIsolationTest::test_cross_tenant_error_message_equals_nonexistent_id_message` → para cada campo, compara `errors.<campo>` de um ID de outra empresa com o de um ID inexistente (`PHP_INT_MAX`).
    - `TenantIsolationTest::test_service_not_offered_by_professional_returns_422_on_service_id` (US-1.3).
    - `TenantIsolationTest::test_company_id_in_payload_is_ignored` → enviar o `company_id` da empresa B cria o booking com o `company_id` do usuário (empresa A).
  - **Traces:** US-3.2 · US-1.3 · workflow 3

### Phase 4.3: Ação de criação e resposta

- [ ] **Task:** DTO `App\Data\CreateBookingData`.
  - **Acceptance criteria:**
    - Classe `final readonly` com `companyId`, `professionalId`, `customerId`, `serviceId` (int) e `startsAt` (`CarbonImmutable`, normalizado para UTC).
    - Construtor nomeado `fromRequest(StoreBookingRequest $request)`, que lê só `validated()`, e `companyId` do `$request->user()`.
  - **Traces:** US-1.1 · US-3.2 · workflow 1

- [ ] **Task:** Action `App\Actions\CreateBooking`.
  - **Acceptance criteria:**
    - `handle(CreateBookingData $data): Booking`.
    - Carrega o serviço escopado à empresa (`Service::where('company_id', $data->companyId)->findOrFail($data->serviceId)`).
    - Calcula `ends_at = startsAt->addMinutes(duration_minutes)`.
    - Cria o `Booking` com `status_id = BookingStatus::Confirmed` e `company_id` do DTO.
    - **Não** faz leitura prévia de sobreposição. A não sobreposição é responsabilidade do banco, e a tradução para `409` vem na Phase 5.
    - É a única via de criação. O controller e o worker de concorrência (Phase 5) a reutilizam.
  - **Feature tests:**
    - `CreateBookingActionTest::test_creates_confirmed_booking_with_computed_ends_at`.
    - `CreateBookingActionTest::test_uses_company_from_data_not_from_elsewhere`.
  - **Traces:** US-1.1 · US-1.3 · workflow 1

- [ ] **Task:** API Resource `App\Http\Resources\BookingResource`.
  - **Acceptance criteria:**
    - Campos `id`, `professional_id`, `customer_id`, `service_id`, `starts_at`, `ends_at`, `status` (= `$this->status_id->slug()`) e `created_at`.
    - As datas usam `->utc()->format('Y-m-d\TH:i:s\Z')`.
    - O resource não expõe `company_id` nem `status_id`.
  - **Traces:** US-1.1 · workflow 1

- [ ] **Task:** Controller `App\Http\Controllers\BookingController@store`.
  - **Acceptance criteria:**
    - O método recebe `StoreBookingRequest` e `CreateBooking`.
    - Retorna `BookingResource::make($booking)->response()->setStatusCode(201)`, com o corpo envelopado em `data`.
    - O controller não contém regra de negócio além de orquestrar o DTO, a Action e o Resource.
  - **Traces:** US-1.1 · workflow 1

- [ ] **Task:** Testes de criação bem-sucedida e de cálculo do término.
  - **Acceptance criteria:**
    - Todas as datas são relativas ao relógio congelado.
  - **Feature tests:**
    - `CreateBookingTest::test_valid_request_returns_201_with_resource` → `assertCreated()` e `assertJsonStructure(['data' => [...8 campos]])`.
    - `CreateBookingTest::test_booking_is_persisted_confirmed` → `status_id = 1` no banco e `"status": "confirmed"` na resposta.
    - `CreateBookingTest::test_dates_are_serialized_in_utc` → `starts_at` enviado como `2026-01-02T14:00:00-03:00` volta como `2026-01-02T17:00:00Z`.
    - `CreateBookingTest::test_booking_belongs_to_authenticated_user_company`.
    - `CreateBookingTest::test_ends_at_is_starts_at_plus_service_duration` → serviço de 45 min com `starts_at = 2026-01-02T14:00:00-03:00` gera `ends_at = 2026-01-02T17:45:00Z`.
    - `CreateBookingTest::test_ends_at_in_payload_is_ignored`.
    - `CreateBookingTest::test_changing_service_duration_does_not_change_existing_bookings` → snapshot de `ends_at`.
  - **Traces:** US-1.1 · US-1.3 · workflow 1

---

## Phase 5: Conflito de horários e concorrência real

**Goal:** traduzir a violação da exclusion constraint em `409` e provar, com processos paralelos reais, que só uma reserva sobreposta é confirmada. · **Depends on:** Phase 4 · **Covers:** US-2.1 · US-2.2 · US-5.1 (tratamento de erros na API) · workflow 2 · workflow 4 (item 8) · `bookings`

### Phase 5.1: Tradução do conflito para `409`

- [ ] **Task:** Exceção de domínio `App\Exceptions\BookingConflictException` e tradução na `CreateBooking`.
  - **Acceptance criteria:**
    - O insert da `CreateBooking` roda dentro de `DB::transaction()`.
      - Em produção, é uma transação curta própria.
      - Sob o `RefreshDatabase`, vira savepoint, e o `ROLLBACK TO SAVEPOINT` acontece antes de a exceção chegar ao handler. Assim, a resposta `409` e as asserções seguintes do teste não esbarram em `25P02`.
      - A captura da `QueryException` fica **fora** do closure da transação.
    - A `CreateBooking` captura `QueryException` e verifica se `($e->errorInfo[0] ?? null) === '23P01'` e se a mensagem cita `bookings_no_overlap`. Nesse caso, lança `BookingConflictException`. Qualquer outra `QueryException` é relançada sem tratamento.
    - `BookingConflictException::render()` retorna `response()->json(['message' => 'O profissional já possui um agendamento nesse horário.'], 409)`.
    - Não há lock na aplicação, cache, Redis nem `SELECT` de sobreposição antes do `INSERT`.
  - **Traces:** US-2.1 · US-2.2 · workflow 2 · `bookings`

- [ ] **Task:** Testes de conflito sequencial via API.
  - **Acceptance criteria:**
    - O relógio é congelado, e os horários são `now()->addDay()->setTime(...)`.
    - Existe um booking `confirmed` de 14:00 a 15:00 (serviço de 60 min) para o profissional P.
    - Toda resposta `409` traz exatamente a mensagem definida, e a contagem de bookings não muda.
    - Depois de um `409`, o mesmo teste consegue fazer nova requisição e novas consultas, sem `25P02`.
  - **Feature tests:**
    - `BookingConflictTest::test_partial_overlap_returns_409` → data provider com serviço de 60 min às 14:30 e às 13:30.
    - `BookingConflictTest::test_identical_interval_returns_409` → 60 min às 14:00.
    - `BookingConflictTest::test_contained_and_containing_intervals_return_409` → 30 min às 14:15; 180 min às 13:00.
    - `BookingConflictTest::test_conflict_response_body_has_expected_message`.
    - `BookingConflictTest::test_adjacent_intervals_return_201` → 60 min às 15:00 e às 13:00.
    - `BookingConflictTest::test_overlap_with_cancelled_booking_returns_201` → o existente usa `status_id = 2`.
    - `BookingConflictTest::test_overlap_with_other_professional_returns_201`.
    - `BookingConflictTest::test_request_after_conflict_succeeds_in_same_test` → depois de um `409`, um `POST` não sobreposto retorna `201`, e `assertDatabaseCount('bookings', 2)` funciona. Isso prova que não há transação abortada.
  - **Traces:** US-2.1 · workflow 2

- [ ] **Task:** Teste de que a aplicação não faz checagem prévia de sobreposição.
  - **Acceptance criteria:**
    - A garantia vem apenas da constraint do banco.
  - **Feature tests:**
    - `NoApplicationLevelCheckTest::test_create_booking_issues_no_overlap_select_before_insert` → `DB::listen` captura as queries de `CreateBooking::handle()`. Nenhum `select` sobre `bookings` ocorre antes do `insert into "bookings"`.
    - `NoApplicationLevelCheckTest::test_action_throws_conflict_exception_on_second_overlapping_insert` → duas chamadas à Action com o mesmo intervalo; a segunda lança `BookingConflictException`.
  - **Traces:** US-2.2 · workflow 2

- [ ] **Task:** Teste de que outras violações do banco viram `500`.
  - **Acceptance criteria:**
    - Só a `23P01` é tratada como regra de negócio. Qualquer outra violação que escape da validação é bug.
  - **Feature tests:**
    - `UnexpectedDatabaseErrorTest::test_non_overlap_query_exception_returns_500` → a `CreateBooking` é substituída no container por um fake que lança `QueryException` com SQLSTATE `23503`; `POST /api/bookings` retorna `500`, e nada é persistido.
  - **Traces:** US-5.1 · workflow 4

### Phase 5.2: Teste de concorrência real com processos paralelos

- [ ] **Task:** Worker `tests/Concurrency/create_booking_worker.php`.
  - **Acceptance criteria:**
    - Inicialização: o worker carrega `vendor/autoload.php` e `bootstrap/app.php`, faz o bootstrap do Console Kernel e lê um JSON de `argv[1]` com `company_id`, `professional_id`, `customer_id`, `service_id`, `starts_at` e `lock_key`.
    - Banco: usa as variáveis `DB_*` recebidas pelo ambiente e aborta antes de qualquer escrita se `DB_DATABASE !== 'agendamento_testing'` (Phase 1.2).
    - Largada, com timeouts em duas etapas e compatíveis com o orçamento do orquestrador:
      1. **Barreira:** `SET lock_timeout = '15s'` e depois `SELECT pg_advisory_lock_shared(:lock_key)`, que bloqueia até o orquestrador liberar o lock exclusivo. O limite de 15 s é maior que os 10 s que o orquestrador espera pela barreira.
      2. **Insert:** já com o lock compartilhado obtido, `SET lock_timeout = '5s'` e `SET statement_timeout = '5s'`.
    - Em seguida, chama `app(CreateBooking::class)->handle(new CreateBookingData(...))` e libera o lock com `pg_advisory_unlock_shared`.
    - Saída: imprime uma única linha JSON — `{"result":"created","id":N}`, `{"result":"conflict"}` (quando captura `BookingConflictException`) ou `{"result":"error","message":"..."}` (qualquer outro `Throwable`) — e termina sempre com exit code `0`.
  - **Traces:** US-2.2 · workflow 2

- [ ] **Task:** Orquestrador `Tests\Feature\Concurrency\ConcurrentBookingTest`.
  - **Acceptance criteria:**
    - Configuração da classe:
      - fica em `#[Group('concurrency')]`;
      - **não** usa `RefreshDatabase` nem `DatabaseMigrations`. O `DatabaseMigrations` deixaria o banco sem tabelas após o rollback e quebraria os testes seguintes que usam `RefreshDatabase`.
    - `setUp()`:
      - garante o schema migrado (`$this->artisan('migrate')`, que é idempotente);
      - remove sobras de execuções anteriores (empresas com o prefixo `concurrency-`);
      - cria, com commit real, uma empresa exclusiva `concurrency-<uuid>` com user, professional, customer, um serviço de 60 min e o vínculo.
    - Paralelismo:
      - se `ParallelTesting::token()` estiver definido (`--parallel`), o teste falha com uma mensagem explicativa, porque nunca roda em paralelo com outros testes;
      - o `starts_at` é calculado pelo orquestrador (`now()->addDays(30)->setTime(14, 0)`) e repassado aos workers, que não herdam relógio congelado.
    - `setUp()` e `tearDown()` chamam `TestCase::assertSafeTestDatabase()` antes de migrar, criar ou apagar dados.
    - Largada, sem bloquear o processo principal:
      1. Numa conexão dedicada (`DB::connection('pgsql_orchestrator')`, clonada em runtime da config `pgsql`), o orquestrador executa `pg_advisory_lock(:key)`.
      2. Dispara **5** workers com `Process::pool(...)->start()`, que é **assíncrono** e retorna um `InvokedProcessPool` sem esperar. Cada processo tem `timeout(30)` e `env` contendo `DB_DATABASE=agendamento_testing` e as demais `DB_*`. **É proibido** usar `Process::pool(...)->run()`, `->wait()` ou `Process::concurrently()` antes do unlock: o orquestrador ficaria esperando workers que esperam por ele, até o timeout.
      3. Espera até que existam 5 linhas em `pg_locks` com `locktype = 'advisory' AND granted = false` (polling a cada 50 ms, limite de 10 s; se estourar, o teste falha).
      4. Executa `pg_advisory_unlock(:key)`.
      5. **Só então** chama `$pool->wait()` para coletar os resultados.
      - O unlock fica num `finally`, executado antes do `wait()`. Se o teste falhar antes da largada, o `finally` também encerra os processos vivos (`$pool->running()` / `signal(SIGTERM)`), para nunca deixar workers presos.
    - Orçamento de tempo:
      - barreira ≤ 10 s (orquestrador) < 15 s de `lock_timeout` da barreira (worker);
      - insert ≤ 5 s;
      - boot do worker ≈ 1–2 s;
      - total < 30 s de timeout do processo.
      - Qualquer timeout faz o teste falhar com mensagem que identifica a etapa: barreira, insert ou processo.
    - Asserções:
      - os 5 processos terminam com exit code `0`;
      - as saídas JSON somam exatamente 1 `created`, 4 `conflict` e 0 `error`;
      - o banco tem exatamente 1 booking `confirmed` para aquele profissional e intervalo.
    - `tearDown()` apaga, com commit e nesta ordem, os bookings, a pivô, os services, os customers, os professionals, os tokens, os users e a company criados.
  - **Feature tests:**
    - `ConcurrentBookingTest::test_only_one_of_five_simultaneous_overlapping_bookings_is_confirmed`.
  - **Traces:** US-2.2 · workflow 2 · `bookings` · `professional_service`

- [ ] **Task:** Execução isolada do grupo de concorrência.
  - **Acceptance criteria:**
    - `php artisan test --group=concurrency` roda só os testes de concorrência (Action, Phase 5.2, e HTTP, Phase 5.3) e passa.
    - `php artisan test` (sem `--parallel`) roda a suíte inteira, incluindo o grupo de concorrência, e passa.
    - Depois da suíte completa, nenhum dado da empresa `concurrency-*` permanece em `agendamento_testing`, e nenhum processo `artisan serve` ou worker continua vivo.
    - `php artisan test --group=concurrency` passa 5 vezes seguidas, sem flakiness.
  - **Traces:** US-2.2 · workflow 2

### Phase 5.3: Concorrência HTTP ponta a ponta

O teste da Phase 5.2 prova a camada de negócio e a constraint. Este prova o fluxo completo: autenticação Sanctum, Form Request, Action, tradução para `409`, serialização e rota, com requisições HTTP simultâneas reais contra um servidor multi-worker.

- [ ] **Task:** Helper `Tests\Support\TestHttpServer`, que sobe um servidor HTTP real durante o teste.
  - **Acceptance criteria:**
    - `start()`:
      - verifica `assertSafeTestDatabase()`;
      - escolhe uma porta livre (`stream_socket_server('tcp://127.0.0.1:0')`, lê a porta e fecha o socket);
      - inicia, com `Process::start()` (assíncrono), o comando `php artisan serve --host=127.0.0.1 --port=<porta> --no-reload`;
      - passa `env` com `APP_ENV=testing`, `PHP_CLI_SERVER_WORKERS=5` (necessário para atender requisições em paralelo), `DB_DATABASE=agendamento_testing` e as demais `DB_*`;
      - aguarda `GET /up` retornar `200` (polling a cada 100 ms, limite de 10 s; se estourar, falha).
    - `stop()` encerra o processo e é sempre chamado num `finally` ou no `tearDown`. Nenhum processo fica órfão.
    - `url(string $path): string` retorna a URL absoluta.
  - **Traces:** US-2.2 · workflow 2

- [ ] **Task:** Orquestrador `Tests\Feature\Concurrency\ConcurrentBookingHttpTest`.
  - **Acceptance criteria:**
    - Segue as mesmas regras de isolamento da Phase 5.2:
      - `#[Group('concurrency')]`, sem `RefreshDatabase`/`DatabaseMigrations`;
      - falha sob `--parallel`;
      - empresa exclusiva `concurrency-<uuid>` criada com commit real;
      - limpeza no `tearDown` guardada por `assertSafeTestDatabase()`.
    - O usuário recebe um token Sanctum real (`createToken('concurrency')`), com commit, para que o servidor o enxergue.
    - `starts_at` = `now()->addDays(30)->setTime(14, 0)`, com offset, calculado pelo orquestrador. O servidor não herda o relógio congelado.
    - Disparo: as 5 requisições `POST /api/bookings`, com payload idêntico e `Authorization: Bearer <token>`, saem **simultaneamente** via `Http::pool()`, que usa requisições concorrentes do Guzzle/curl-multi, com `timeout(15)` por requisição.
    - Asserções:
      - exatamente 1 resposta `201` e 4 respostas `409`, todas com o corpo JSON esperado (`data` no `201`; `message` exata no `409`);
      - nenhuma resposta `5xx` nem timeout;
      - exatamente 1 booking `confirmed` no banco para aquele profissional e intervalo.
    - O resultado é determinístico mesmo que o servidor serialize parte das requisições. Requisições processadas em sequência também recebem `409`. A prova da corrida no nível do banco continua sendo a Phase 5.2.
  - **Feature tests:**
    - `ConcurrentBookingHttpTest::test_five_simultaneous_http_requests_yield_one_201_and_four_409`.
  - **Traces:** US-2.2 · US-2.1 · US-3.1 · workflow 1 · workflow 2

---

## Phase 6: Seeder de demonstração restrito ao ambiente local

**Goal:** permitir exercitar a API localmente com dados de pelo menos duas empresas e tokens Sanctum emitidos com segurança. · **Depends on:** Phase 5 · **Covers:** US-4.1 · `personal_access_tokens` · `companies` · `users` · `professionals` · `customers` · `services` · `professional_service`

### Phase 6.1: `DemoSeeder`

- [ ] **Task:** Implementar `Database\Seeders\DemoSeeder` e chamá-lo pelo `DatabaseSeeder`.
  - **Acceptance criteria:**
    - Guarda de ambiente: se `! app()->environment('local')`, o seeder exibe um aviso pelo `$this->command->warn` e retorna **sem criar dados nem tokens**.
    - Em ambiente `local`, cria **2 empresas** (`Empresa A` e `Empresa B`). Cada uma tem:
      - 1 usuário (`a@agendamento.test` ou `b@agendamento.test`, senha `password`);
      - 2 profissionais e 2 clientes;
      - 2 serviços com durações diferentes (30 e 60 min).
    - Vínculos na pivô: o profissional 1 oferece os 2 serviços, e o profissional 2 oferece só o serviço de 30 min. Assim fica pelo menos um par **não** vinculado, para testar o `422`.
    - Tokens: cada usuário recebe `createToken('demo', ['*'], now()->addDays(7))`.
    - Exibição: o texto plano de cada token aparece **uma única vez**, em `$this->command->table(['Empresa', 'Usuário', 'Token'], ...)`, seguido de uma tabela com os IDs de profissionais, clientes e serviços (incluindo os vínculos) por empresa.
    - Nenhum token é gravado em arquivo ou log.
    - O `DatabaseSeeder` remove o usuário de exemplo do skeleton e passa a chamar `DemoSeeder`. O uso documentado é `php artisan migrate:fresh --seed`.
  - **Traces:** US-4.1 · `personal_access_tokens` · `professional_service`

- [ ] **Task:** Testes do `DemoSeeder`.
  - **Acceptance criteria:**
    - Os testes usam `RefreshDatabase`. O ambiente `local` é simulado com `app()->detectEnvironment(fn () => 'local')` e restaurado no `tearDown`.
  - **Feature tests:**
    - `DemoSeederTest::test_seeder_aborts_outside_local_environment` → em `testing`, `companies` e `personal_access_tokens` ficam vazios.
    - `DemoSeederTest::test_seeder_creates_two_companies_with_full_dataset` → por empresa: ≥1 usuário, 2 profissionais, 2 clientes, 2 serviços com `duration_minutes` distintos.
    - `DemoSeederTest::test_seeder_leaves_at_least_one_unlinked_professional_service_pair`.
    - `DemoSeederTest::test_tokens_are_named_demo_and_expire_in_seven_days` → `expires_at` igual a `now()->addDays(7)`, com relógio congelado.
    - `DemoSeederTest::test_printed_token_authenticates_booking_endpoint` → captura a saída do comando, extrai um token e faz `POST /api/bookings` com payload válido da mesma empresa: `201`.
    - `DemoSeederTest::test_tokens_are_not_logged` → `Log::spy()` e `shouldNotHaveReceived` para qualquer nível de log contendo o token.
  - **Traces:** US-4.1

---

## Phase 7: Qualidade, documentação e verificação final

**Goal:** fechar o entregável de estudo com código formatado, instruções reproduzíveis e a suíte inteira verde. · **Depends on:** Phase 6 · **Covers:** Tech Stack (Qualidade) · US-2.2 · US-4.1 · todas as histórias (verificação)

### Phase 7.1: Qualidade e documentação

- [ ] **Task:** Formatar o código com Laravel Pint.
  - **Acceptance criteria:**
    - `vendor/bin/pint --test` sai com código `0` em `app/`, `database/`, `routes/` e `tests/`.
  - **Traces:** Tech Stack (Qualidade) · clean code (project description)

- [ ] **Task:** Reescrever o `README.md` do projeto, substituindo o README padrão do Laravel.
  - **Acceptance criteria:**
    - Seções:
      1. Visão geral e regra central.
      2. Requisitos: PHP 8.5 e Docker.
      3. Setup: `docker compose up -d`, criação de `agendamento_testing` (script de init ou `createdb` manual), `composer install` e `php artisan migrate:fresh --seed` em `local`, com a obtenção dos tokens.
      4. Contrato de `POST /api/bookings`, com exemplo `curl` e respostas `201`, `401`, `409` e `422`.
      5. Testes: `php artisan test`, `php artisan test --group=concurrency` e o aviso de nunca usar `--parallel` com esse grupo.
      6. Decisões de design: exclusion constraint, FKs compostas, IDs fixos de status, cálculo de `ends_at` e o motivo de PostgreSQL nos testes.
    - O comentário provisório sobre `createdb` no `compose.yaml` (Phase 1.2) é removido.
  - **Traces:** US-4.1 · US-2.2 · Tech Stack · workflow 1

- [ ] **Task:** Verificação final de rastreabilidade e da suíte.
  - **Acceptance criteria:**
    - `php artisan test` roda a suíte completa, verde, contra `agendamento_testing`.
    - Cada critério de aceitação de US-1.1, US-1.2, US-1.3, US-2.1, US-2.2, US-3.1, US-3.2, US-4.1 e US-5.1 em `.spec/init/user-stories.md` corresponde a pelo menos um teste nomeado nesta spec. Uma tabela de checklist critério → teste é anexada ao README ou ao PR final.
    - `.spec/init/user-stories.md` tem o status de todas as histórias atualizado de `Pending` para `Done`.
  - **Traces:** US-1.1 · US-1.2 · US-1.3 · US-2.1 · US-2.2 · US-3.1 · US-3.2 · US-4.1 · US-5.1

## Open Questions

- **`starts_at` sem offset:** a spec exige ISO 8601, mas não diz se um timestamp sem fuso (`2026-01-02T14:00:00`) é aceito. Este plano **rejeita com `422`** (Phase 4.2), porque a coluna é `timestamptz` e um horário sem fuso seria ambíguo. Se você preferir interpretar como UTC, ajuste a regra `date_format` e o teste correspondente.

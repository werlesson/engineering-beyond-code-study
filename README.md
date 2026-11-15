# Agendamento

API **multi-tenant** de agendamento de profissionais, construída como projeto de estudo em Laravel.

## 1. Visão geral e regra central

Várias **empresas** compartilham a mesma aplicação e o mesmo banco. Cada uma enxerga apenas os próprios
profissionais, clientes, serviços e agendamentos. O tenant é sempre o `company_id` do usuário autenticado
(token Bearer do Laravel Sanctum), nunca um valor do payload.

O escopo é um único endpoint, **`POST /api/bookings`**, que reserva um serviço com um profissional para um
cliente a partir de um horário de início. O término é calculado pela duração do serviço.

> **Regra central:** um profissional nunca pode ter dois agendamentos `confirmed` com horários sobrepostos,
> inclusive sob requisições concorrentes atendidas por servidores diferentes. A garantia é imposta pelo
> próprio PostgreSQL, com uma *exclusion constraint*, e não por uma checagem na aplicação.

Os intervalos são semiabertos, `[starts_at, ends_at)`: um agendamento que termina às 10:00 e outro que
começa às 10:00 **não** conflitam. Empresas, usuários, profissionais, clientes e serviços são criados por
seeders e factories; não há endpoints de cadastro, listagem, cancelamento ou login.

## 2. Requisitos

- **PHP 8.5** com a extensão `pdo_pgsql` e o Composer.
- **Docker** com Docker Compose, para subir o PostgreSQL 18 (`postgres:18-alpine`).

## 3. Setup

```bash
# 1. Dependências e .env
composer install
cp .env.example .env
php artisan key:generate

# 2. PostgreSQL (porta 5435 no host, banco `agendamento`, usuário `agendamento`)
docker compose up -d
```

### Banco de testes `agendamento_testing`

Os testes rodam num banco dedicado, `agendamento_testing`, no mesmo servidor PostgreSQL. O script
`docker/pgsql/create-testing-database.sql` é montado em `/docker-entrypoint-initdb.d/` e cria esse banco
automaticamente **na primeira inicialização do volume**.

Se o volume `pgsql-data` já existia antes do script, crie o banco manualmente:

```bash
docker compose exec pgsql createdb -U agendamento agendamento_testing
```

Para conferir, os dois bancos devem aparecer em:

```bash
docker compose exec pgsql psql -U agendamento -lqt
```

### Migrations, dados de demonstração e tokens

Com `APP_ENV=local` (o padrão do `.env.example`):

```bash
php artisan migrate:fresh --seed
```

O `DemoSeeder` cria duas empresas (`Empresa A` e `Empresa B`), cada uma com 1 usuário, 2 profissionais,
2 clientes e 2 serviços (30 e 60 minutos). O profissional 1 executa os dois serviços; o profissional 2 executa
só o de 30 minutos, deixando um par profissional/serviço sem vínculo para exercitar o `422`.

Ao final, o console mostra duas tabelas:

- **Empresa / Usuário / Token:** um token Sanctum por usuário, chamado `demo`, que expira em 7 dias. O texto
  plano aparece **uma única vez**, apenas no console; ele não é gravado em arquivo nem em log. Copie-o na hora.
- **IDs:** profissionais, clientes, serviços (com duração) e os vínculos profissional → serviços de cada
  empresa, para montar as requisições.

Os tokens só são emitidos quando `app()->environment('local')`. Em qualquer outro ambiente o `DemoSeeder` é
ignorado sem criar dados nem tokens. Para gerar tokens novos, rode `php artisan migrate:fresh --seed` de novo.

Por fim, suba o servidor:

```bash
php artisan serve
```

## 4. Contrato de `POST /api/bookings`

| Campo | Regras |
|---|---|
| `professional_id` | obrigatório, inteiro, deve existir na empresa do usuário |
| `customer_id` | obrigatório, inteiro, deve existir na empresa do usuário |
| `service_id` | obrigatório, inteiro, deve existir na empresa do usuário e ser executado pelo profissional |
| `starts_at` | obrigatório, ISO 8601 com offset ou `Z` (ex.: `2026-11-20T14:00:00-03:00`), no futuro |

- `ends_at` é **calculado** (`starts_at + duration_minutes` do serviço) e ignorado se vier no payload.
- `company_id` é ignorado se vier no payload; o tenant é sempre o do usuário autenticado.
- Um ID de outra empresa recebe exatamente o mesmo erro que um ID inexistente.
- As datas da resposta são sempre ISO 8601 em UTC.

### Exemplo

```bash
curl -i -X POST http://localhost:8000/api/bookings \
  -H "Authorization: Bearer <token-do-seeder>" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "professional_id": 1,
    "customer_id": 1,
    "service_id": 2,
    "starts_at": "2026-11-20T14:00:00-03:00"
  }'
```

### `201 Created`: agendamento criado

```json
{
  "data": {
    "id": 1,
    "professional_id": 1,
    "customer_id": 1,
    "service_id": 2,
    "starts_at": "2026-11-20T17:00:00Z",
    "ends_at": "2026-11-20T18:00:00Z",
    "status": "confirmed",
    "created_at": "2026-11-15T12:00:00Z"
  }
}
```

### `401 Unauthorized`: sem token, token inválido, revogado ou expirado

```json
{
  "message": "Unauthenticated."
}
```

### `409 Conflict`: o profissional já tem um agendamento `confirmed` sobreposto

```json
{
  "message": "O profissional já possui um agendamento nesse horário."
}
```

### `422 Unprocessable Entity`: payload inválido

Formato padrão de validação do Laravel (`message` + `errors`). Exemplo com um serviço que o profissional não
executa:

```json
{
  "message": "The selected service id is invalid.",
  "errors": {
    "service_id": [
      "The selected service id is invalid."
    ]
  }
}
```

Qualquer outra violação de constraint que escape da validação é tratada como bug e resulta em `500`.

## 5. Testes

Os testes usam PHPUnit contra o PostgreSQL real, no banco `agendamento_testing` (definido em `phpunit.xml`).
O banco `agendamento` de desenvolvimento nunca é tocado: uma proteção em `Tests\TestCase` aborta qualquer
teste que tente migrar ou escrever fora de `agendamento_testing`. O container precisa estar no ar
(`docker compose up -d`).

```bash
# Suíte completa
php artisan test        # ou: composer test (limpa o cache de config antes)

# Só os testes de concorrência real (processos PHP independentes disputando o mesmo horário)
php artisan test --group=concurrency
```

> **Atenção:** **nunca** rode o grupo `concurrency` com `--parallel`. Esses testes fazem commits reais,
> visíveis entre processos, e coordenam os workers com advisory locks do PostgreSQL. Executá-los em paralelo
> com outros testes quebra o isolamento e torna o resultado não determinístico.

### Checagem manual de estabilidade

Para ganhar confiança de que os testes de concorrência não são intermitentes, rode o grupo várias vezes
seguidas. O laço para na primeira falha:

```bash
for i in 1 2 3 4 5; do php artisan test --group=concurrency || break; done
```

Essa checagem é manual e não faz parte da validação automática.

A rastreabilidade entre os critérios de aceitação das user stories e os testes está em
[`docs/traceability.md`](docs/traceability.md).

## 6. Decisões de design

### Exclusion constraint em vez de checagem na aplicação

```sql
ALTER TABLE bookings ADD CONSTRAINT bookings_no_overlap
  EXCLUDE USING gist (professional_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&)
  WHERE (status_id = 1);
```

Uma checagem do tipo "existe agendamento sobreposto?" seguida de um `INSERT` tem uma janela de corrida: duas
requisições em servidores diferentes leem "não existe" e ambas gravam. A exclusion constraint (com a extensão
`btree_gist`) fecha essa janela no próprio banco: das transações concorrentes, apenas uma consegue gravar o
intervalo. Por isso a aplicação **não** faz checagem prévia, nem usa lock em memória, cache ou Redis.

A `CreateBooking` insere com `ON CONFLICT DO NOTHING RETURNING id`, tendo a constraint como árbitro. Com um
`INSERT` simples, inserts concorrentes do mesmo intervalo esperam uns pelos outros e podem entrar em deadlock
(`40P01`). Com a inserção especulativa, o perdedor apenas não insere, e a ausência de linha retornada vira
`409`. A tradução do SQLSTATE `23P01` (exclusion violation) para `409` é mantida como defesa.

O intervalo `'[)'` é semiaberto, o que torna intervalos adjacentes compatíveis. O predicado
`WHERE (status_id = 1)` faz com que só agendamentos `confirmed` bloqueiem o horário; um `cancelled` libera o
intervalo.

### FKs compostas com `company_id`

`professionals`, `customers` e `services` têm `UNIQUE (company_id, id)`, que serve de alvo para FKs compostas:

- `professional_service (company_id, professional_id, service_id)` referencia `professionals (company_id, id)`
  e `services (company_id, id)`;
- `bookings (company_id, customer_id)` referencia `customers (company_id, id)`;
- `bookings (company_id, professional_id, service_id)` referencia
  `professional_service (company_id, professional_id, service_id)`.

Assim, o banco recusa (`23503`) qualquer relação entre empresas diferentes e qualquer agendamento de um serviço
que o profissional não executa, mesmo que a validação da aplicação seja contornada (insert direto, bug, job
futuro). A validação da API continua existindo para devolver um `422` claro; o banco é a última linha de
defesa. Completam as invariantes: colunas de negócio `NOT NULL`, `CHECK (ends_at > starts_at)` e
`CHECK (duration_minutes > 0)`.

### IDs fixos de status

`booking_statuses` é uma lookup table com IDs **fixos**, inseridos pela própria migration: `1 = confirmed` e
`2 = cancelled`. O enum PHP `App\Enums\BookingStatus: int` espelha esses IDs, e a API expõe o slug
(`"status": "confirmed"`). Os IDs precisam ser fixos porque o predicado da exclusion constraint
(`WHERE status_id = 1`) não pode consultar outra tabela. Como o seed está na migration, os IDs existem em
todo banco migrado, sem depender de `db:seed`.

### Cálculo de `ends_at`

O cliente envia apenas `starts_at`. A API calcula `ends_at = starts_at + service.duration_minutes` e o
persiste. O valor gravado é um snapshot: alterar a duração do serviço depois não muda agendamentos existentes.
O banco garante apenas que o resultado é um intervalo válido (`ends_at > starts_at`). A regra "`starts_at` no
futuro" depende do relógio e por isso fica só na API, já que um `CHECK` com `now()` não é adequado.

### Por que PostgreSQL nos testes

Os testes usam o mesmo PostgreSQL do desenvolvimento, nunca SQLite. A exclusion constraint, a extensão
`btree_gist`, os tipos `tstzrange`, as FKs compostas e os SQLSTATEs verificados (`23P01`, `23503`, `23514`,
`23502`) são específicos do PostgreSQL. Testar em outro banco provaria um comportamento que não é o de
produção, e o teste de concorrência real precisa de commits visíveis entre processos e de advisory locks.

Os testes que dependem de "agora" congelam o relógio em `2026-01-01T12:00:00Z` (`Tests\TestCase::FROZEN_NOW`)
e usam datas relativas, para não "vencerem" com o tempo.

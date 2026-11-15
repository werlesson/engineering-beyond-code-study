# Agendamento — Project Description

## Overview

**Agendamento** é uma API **multi-tenant** de agendamento de profissionais, construída como projeto de **estudo**. Várias **empresas** usam a mesma aplicação e banco de dados, e cada uma vê somente os próprios **profissionais**, **clientes** e **agendamentos**. O isolamento acontece pela coluna `company_id`, e o tenant é sempre derivado do usuário autenticado, nunca do payload.

O núcleo do sistema é um único endpoint, **`POST /api/bookings`**, que reserva um **serviço** com um profissional para um cliente a partir de um horário de início. O **horário de término é calculado** pela duração do serviço. A regra central: **um profissional não pode ter dois agendamentos ativos com horários sobrepostos**. Essa garantia precisa valer **inclusive sob concorrência entre múltiplos servidores**. Por isso ela é imposta pelo próprio **PostgreSQL** com uma *exclusion constraint*, e não apenas por uma checagem na aplicação.

**Fronteira do MVP:** apenas o endpoint de criação de agendamento. Empresas, usuários, profissionais, clientes e serviços **já existem** no sistema (são criados por seeders/factories), e **não haverá endpoints de cadastro** para eles. Também não há endpoints de listagem ou cancelamento. A prioridade é **simplicidade, clareza, clean code e segurança**.

### Key Concepts

- **Company (Empresa):** o tenant. Agrupa usuários, profissionais, clientes e agendamentos. Dados de uma empresa nunca são visíveis ou manipuláveis por outra.
- **User (Usuário):** quem chama a API. Pertence a exatamente uma Company e se autentica por **token Bearer do Laravel Sanctum**. O tenant de toda operação é o `company_id` do usuário autenticado.
- **Professional (Profissional):** pertence a exatamente uma Company e pode receber vários agendamentos ao longo do dia, desde que não se sobreponham.
- **Customer (Cliente):** entidade própria, pertencente a exatamente uma Company. É referenciado no agendamento por `customer_id`.
- **Service (Serviço):** pertence a exatamente uma Company e tem uma duração fixa em minutos (`duration_minutes`, inteiro maior que 0). Exemplo: "Corte de cabelo", 30 minutos.
- **Professional ↔ Service:** relação N:N (tabela pivô) que define quais serviços cada profissional executa. Um profissional só pode ser agendado para um serviço que ele executa.
- **Booking (Agendamento):** reserva de **um** Service, com um Professional, para um Customer, no intervalo `[starts_at, ends_at)`. Pertence a uma Company e guarda `service_id`.
- **Intervalo de horário:** semiaberto `[starts_at, ends_at)`, armazenado como `timestamptz`. O cliente envia apenas `starts_at`. A API calcula `ends_at = starts_at + service.duration_minutes` e o persiste. O `ends_at` gravado funciona como snapshot: mudar a duração do serviço depois não altera bookings já existentes. Um `ends_at` enviado no payload é ignorado. Intervalos **adjacentes** (um termina às 10:00 e o outro começa às 10:00) **não conflitam**.
- **Status do Booking:** valores da lookup table `booking_statuses`, com IDs **fixos**: `1` = `confirmed` e `2` = `cancelled`. O booking guarda `status_id` (FK). Na aplicação, os IDs são espelhados pelo enum PHP `BookingStatus: int` (que não é coluna enum no banco), e a API expõe o **slug** (`"status": "confirmed"`). Todo agendamento é criado com `status_id = 1`. Só agendamentos `confirmed` são **ativos** e bloqueiam o horário; um `cancelled` libera o intervalo. No MVP não há endpoint de cancelamento.
- **Conflito de horário:** dois bookings com `status_id = 1` (`confirmed`) do mesmo profissional cujos intervalos se intersectam (`tstzrange(starts_at, ends_at, '[)') && ...`).
- **Integridade multi-tenant no banco:** cada tabela de tenant (`professionals`, `customers`, `services`) tem `UNIQUE (company_id, id)`. As referências usam **FKs compostas** que incluem `company_id`, então o banco recusa qualquer relação entre empresas diferentes, mesmo que a validação da aplicação seja contornada:
  - `professional_service (company_id, professional_id, service_id)` referencia `professionals (company_id, id)` e `services (company_id, id)`;
  - `bookings (company_id, customer_id)` referencia `customers (company_id, id)`;
  - `bookings (company_id, professional_id, service_id)` referencia `professional_service (company_id, professional_id, service_id)`. Isso garante, ao mesmo tempo, que o profissional executa o serviço e que ambos são da empresa do booking.
- **Invariantes protegidas pelo banco:** todas as colunas de negócio são `NOT NULL`, e o banco impõe:
  - `CHECK (ends_at > starts_at)` em `bookings`;
  - `bookings.status_id` como FK para `booking_statuses.id`; um status inexistente falha com `23503`;
  - `CHECK (duration_minutes > 0)` em `services`;
  - a exclusion constraint de não sobreposição (abaixo).

  A regra "`starts_at` no futuro" depende do relógio e por isso fica **só na API**: um `CHECK` com `now()` não é adequado. O cálculo de `ends_at` também é responsabilidade da API; o banco garante apenas que o resultado é um intervalo válido.
- **Exclusion constraint:** `EXCLUDE USING gist (professional_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE (status_id = 1)`, que exige a extensão `btree_gist`. O predicado usa o ID fixo porque uma constraint não pode consultar a lookup table. É a fonte da verdade para RF04 e RF05: das transações concorrentes, apenas uma consegue gravar o intervalo.
- **Relógio controlado nos testes:** testes que dependem de "agora" (regra de `starts_at` no futuro, datas de bookings) congelam o relógio com `$this->freezeTime()` ou `$this->travelTo()` em um instante fixo, por exemplo `2026-01-01T12:00:00Z`. Todas as datas dos testes são relativas a esse instante (`now()->addDay()->setTime(14, 0)`), nunca datas absolutas que "vencem" com o tempo. Os testes de concorrência rodam em processos separados, que não herdam o relógio congelado; por isso o orquestrador calcula `starts_at` e o repassa explicitamente aos workers.
- **Seeder de demonstração:** cria empresas, usuários, profissionais, clientes, serviços e vínculos, e emite tokens Sanctum **somente quando `app()->environment('local')`**. Em qualquer outro ambiente, o `DemoSeeder` aborta sem criar tokens. Os tokens se chamam `demo`, expiram em 7 dias (`expires_at`) e o texto plano é exibido **uma única vez** no console (`$this->command->table`). O seeder não grava os tokens em arquivo nem em log.

## Tech Stack

| Layer | Technology |
|---|---|
| Linguagem | PHP 8.5 (constraint `^8.3`) |
| Framework | Laravel 13.42 |
| Autenticação | Laravel Sanctum (personal access tokens, Bearer). **A instalar**, junto com `routes/api.php` via `php artisan install:api` |
| Banco de dados | PostgreSQL 18 (`postgres:18-alpine` via `compose.yaml`, porta 5435), extensão `btree_gist` |
| Testes | PHPUnit 12.5 (`php artisan test`), Mockery, Faker; banco dedicado `agendamento_testing` no mesmo PostgreSQL; `Process::pool` para os testes de concorrência |
| Qualidade | Laravel Pint |
| Dev env | Docker Compose (somente PostgreSQL); `composer dev` |
| Cache / Queue | `database` (padrão do skeleton; não usados pelo fluxo de agendamento) |
| Frontend | Vite 8 + Tailwind 4 (presentes no skeleton, **fora de escopo**) |

## Core Workflows

### 1. Criar agendamento (`POST /api/bookings`)

**Requisição**

```http
POST /api/bookings
Authorization: Bearer <sanctum-token>
Content-Type: application/json
Accept: application/json

{
  "professional_id": 12,
  "customer_id": 345,
  "service_id": 7,
  "starts_at": "2026-11-20T14:00:00-03:00"
}
```

**Passos**

1. O middleware `auth:sanctum` autentica o token. Sem token ou com token inválido, a resposta é `401 Unauthorized`.
2. O tenant é resolvido como o `company_id` do usuário autenticado. O payload **não** aceita `company_id`.
3. Validação (Form Request):
   - `professional_id`: obrigatório, inteiro, deve existir **na empresa do usuário**.
   - `customer_id`: obrigatório, inteiro, deve existir **na empresa do usuário**.
   - `service_id`: obrigatório, inteiro, deve existir **na empresa do usuário** e ser executado pelo profissional informado (registro na pivô professional ↔ service).
   - `starts_at`: obrigatório, data ISO 8601, deve estar no futuro.
   - Falhas retornam `422 Unprocessable Entity`. Um ID de outra empresa é tratado exatamente como um ID inexistente, então a resposta não revela que o recurso existe em outro tenant.
4. A aplicação calcula `ends_at = starts_at + service.duration_minutes`.
5. A aplicação insere o booking com `status_id = 1` (`confirmed`), `service_id`, `ends_at` calculado e `company_id` do usuário.
6. Se o PostgreSQL rejeitar o insert pela exclusion constraint (SQLSTATE `23P01`), a aplicação traduz o erro para `409 Conflict`.
7. Em caso de sucesso, a resposta é `201 Created`.

**Resposta de sucesso**

```json
HTTP/1.1 201 Created
{
  "data": {
    "id": 987,
    "professional_id": 12,
    "customer_id": 345,
    "service_id": 7,
    "starts_at": "2026-11-20T17:00:00Z",
    "ends_at": "2026-11-20T18:00:00Z",
    "status": "confirmed",
    "created_at": "2026-11-15T12:00:00Z"
  }
}
```

**Resposta de conflito**

```json
HTTP/1.1 409 Conflict
{
  "message": "O profissional já possui um agendamento nesse horário."
}
```

### 2. Garantia contra conflito sob concorrência

1. Duas requisições (possivelmente em servidores diferentes) tentam reservar o mesmo profissional em intervalos sobrepostos.
2. Ambas passam pela validação, porque nenhuma checagem de leitura na aplicação é considerada suficiente.
3. Ambas executam o `INSERT`. A exclusion constraint do PostgreSQL serializa o conflito: a primeira transação a gravar vence, e a segunda falha com `23P01` (após esperar o commit da primeira, se as duas estiverem em andamento).
4. A vencedora recebe `201`; a perdedora recebe `409`.
5. Não há lock distribuído, Redis ou lock na aplicação. A corretude depende apenas do banco compartilhado.
6. Um teste de feature deve cobrir o conflito sequencial (sobreposição parcial, total e contida, e a adjacência permitida). Também deve verificar que um booking `cancelled` não bloqueia o horário.
7. Um **teste de concorrência real** é obrigatório, com a orquestração abaixo.
   - **Workers:** o teste dispara **N = 5** processos PHP independentes com `Process::pool` do Laravel. Cada um executa o script `tests/Concurrency/create_booking_worker.php`, que inicializa a aplicação e chama a **mesma Action** usada pelo controller (`CreateBooking`). Todos recebem o mesmo profissional, o mesmo serviço e o mesmo `starts_at`.
   - **Largada simultânea:** antes de disparar os workers, o orquestrador obtém `pg_advisory_lock(<chave>)` numa conexão própria. Cada worker chama `pg_advisory_lock_shared(<chave>)` e fica bloqueado. Quando o orquestrador libera o lock, todos avançam juntos e disputam o insert.
   - **Resultado:** cada worker imprime em JSON `{"result":"created"|"conflict"|"error"}` e termina com exit code 0. O teste exige exatamente 1 `created`, N−1 `conflict` e nenhum `error`. Também exige exatamente 1 booking `confirmed` no banco para aquele profissional e intervalo.
   - **Timeouts:**
     - cada processo tem timeout de 15 s (`Process::timeout(15)`);
     - cada worker define `SET lock_timeout = '5s'` e `SET statement_timeout = '5s'`;
     - o orquestrador libera o advisory lock num `finally`, para nunca deixar workers presos;
     - qualquer timeout faz o teste falhar.
   - **Isolamento do banco de testes:**
     - os testes usam um banco dedicado, `agendamento_testing`, configurado em `phpunit.xml` (`DB_DATABASE`) e repassado aos workers por variável de ambiente;
     - o teste de concorrência **não** usa `RefreshDatabase`, porque os commits precisam ser reais e visíveis entre processos. Ele usa `DatabaseMigrations` ou migra uma vez e cria uma empresa exclusiva por execução;
     - no `tearDown`, o teste apaga os dados dessa empresa;
     - o teste fica no grupo `#[Group('concurrency')]`, para poder rodar isolado (`php artisan test --group=concurrency`) e nunca em paralelo com outros testes (`--parallel` desativado para esse grupo).

### 3. Isolamento de tenant (autorização)

1. Toda leitura e escrita é restrita ao `company_id` do usuário autenticado.
2. O usuário da empresa A não consegue criar um booking com `professional_id`, `customer_id` ou `service_id` da empresa B. A resposta é `422`, como se o ID não existisse.
3. O `company_id` do booking é sempre o do usuário e nunca vem do request.
4. `professional_id`, `customer_id` e `service_id` da mesma requisição devem pertencer à mesma empresa. A validação (item 2) garante isso na API, e as FKs compostas com `company_id` garantem no banco (ver Key Concepts).

### 4. Integridade garantida pelo banco

O banco é a última linha de defesa e deve rejeitar dados inválidos mesmo quando a camada Laravel é contornada (insert direto, bug, job futuro).

1. Um insert em `bookings` com profissional, cliente ou serviço de outra empresa falha por violação de FK composta (SQLSTATE `23503`).
2. Um insert em `bookings` com um serviço que o profissional não executa falha por FK em `professional_service` (`23503`).
3. Um insert com `ends_at <= starts_at` falha por `CHECK` (`23514`).
4. Um insert com `status_id` inexistente em `booking_statuses` falha por FK (`23503`).
5. Um insert em `services` com `duration_minutes <= 0` falha por `CHECK` (`23514`).
6. Um insert sobreposto a um booking `confirmed` (`status_id = 1`) do mesmo profissional falha pela exclusion constraint (`23P01`).
7. Cada invariante acima tem um teste que insere diretamente pelo query builder, sem Form Request, e verifica o SQLSTATE esperado.
8. Na API, essas violações não devem acontecer porque a validação vem antes. A exceção é a `23P01`, que acontece de fato sob concorrência e vira `409`. Qualquer outra violação que escape da validação é um bug e resulta em `500`.

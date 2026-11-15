# Agendamento — Database Schema

<!-- inputs: project-description.md@sha256:269de7f9cebe user-stories.md@sha256:54d87a61d07f -->

## Overview

O modelo gira em torno de **companies** (o tenant). Cada empresa possui **users**, **professionals**, **customers** e **services**. A pivô **professional_service** define quais serviços cada profissional executa. Um **booking** reserva um par profissional/serviço para um cliente no intervalo `[starts_at, ends_at)`, e seu estado vem da lookup **booking_statuses**.

Convenções em vigor:
- **Eloquent / Laravel 13** sobre **PostgreSQL 18**: tabelas no plural em snake_case, `id bigint` autoincremental, FKs `<singular>_id`, `created_at`/`updated_at` e pivô com os singulares em ordem alfabética.
- **Sem soft deletes.**
- Valores categóricos ficam em **lookup tables**, não em colunas enum.
- Para impor no banco o **isolamento multi-tenant**, cada tabela de tenant expõe `UNIQUE (company_id, id)`, e as referências usam **FKs compostas** que incluem `company_id`.
- Horários de agendamento usam `timestamptz`.

## Schema (DBML)

```dbml
// Lookup tables first, then domain tables, then pivots.

Table booking_statuses {
  id bigint [pk, note: 'IDs fixos: 1 = confirmed, 2 = cancelled (sem autoincremento)']
  name varchar [not null]
  slug varchar [unique, not null]
  description text [null]
  created_at timestamp
  updated_at timestamp
}

Table companies {
  id bigint [pk, increment]
  name varchar [not null]
  created_at timestamp
  updated_at timestamp
}

Table users {
  id bigint [pk, increment]
  company_id bigint [ref: > companies.id, not null]
  name varchar [not null]
  email varchar [unique, not null]
  email_verified_at timestamp [null]
  password varchar [not null]
  remember_token varchar(100) [null]
  created_at timestamp
  updated_at timestamp

  indexes {
    company_id
  }
}

Table personal_access_tokens {
  // Tabela padrão do Laravel Sanctum (php artisan install:api)
  id bigint [pk, increment]
  tokenable_type varchar [not null]
  tokenable_id bigint [not null]
  name text [not null]
  token varchar(64) [unique, not null]
  abilities text [null]
  last_used_at timestamp [null]
  expires_at timestamp [null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (tokenable_type, tokenable_id)
    expires_at
  }
}

Table professionals {
  id bigint [pk, increment]
  company_id bigint [ref: > companies.id, not null]
  name varchar [not null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (company_id, id) [unique, name: 'professionals_company_id_id_unique']
  }
}

Table customers {
  id bigint [pk, increment]
  company_id bigint [ref: > companies.id, not null]
  name varchar [not null]
  email varchar [null]
  phone varchar [null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (company_id, id) [unique, name: 'customers_company_id_id_unique']
  }
}

Table services {
  id bigint [pk, increment]
  company_id bigint [ref: > companies.id, not null]
  name varchar [not null]
  duration_minutes integer [not null, note: 'CHECK (duration_minutes > 0)']
  created_at timestamp
  updated_at timestamp

  indexes {
    (company_id, id) [unique, name: 'services_company_id_id_unique']
  }
}

Table bookings {
  id bigint [pk, increment]
  company_id bigint [ref: > companies.id, not null]
  professional_id bigint [not null]
  customer_id bigint [not null]
  service_id bigint [not null]
  status_id bigint [ref: > booking_statuses.id, not null, default: 1]
  starts_at timestamptz [not null]
  ends_at timestamptz [not null, note: 'calculado pela API: starts_at + services.duration_minutes; CHECK (ends_at > starts_at)']
  created_at timestamp
  updated_at timestamp

  indexes {
    (company_id, customer_id)
    (company_id, professional_id, service_id)
    (professional_id, starts_at)
  }

  Note: '''
  EXCLUDE USING gist (
    professional_id WITH =,
    tstzrange(starts_at, ends_at, '[)') WITH &&
  ) WHERE (status_id = 1)   -- requer CREATE EXTENSION btree_gist
  '''
}

// Pivots

Table professional_service {
  company_id bigint [not null]
  professional_id bigint [not null]
  service_id bigint [not null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (professional_id, service_id) [pk]
    (company_id, professional_id, service_id) [unique, name: 'professional_service_company_professional_service_unique']
    service_id
  }
}

// Composite foreign keys (multi-tenant integrity)

Ref: professional_service.(company_id, professional_id) > professionals.(company_id, id) [delete: cascade]
Ref: professional_service.(company_id, service_id) > services.(company_id, id) [delete: cascade]
Ref: bookings.(company_id, customer_id) > customers.(company_id, id) [delete: restrict]
Ref: bookings.(company_id, professional_id, service_id) > professional_service.(company_id, professional_id, service_id) [delete: restrict]
```

## Relationships

- Uma **company** tem muitos **users**, **professionals**, **customers**, **services** e **bookings**.
- Um **user** pertence a uma **company** e tem muitos **personal_access_tokens** (relação polimórfica do Sanctum via `tokenable_type`/`tokenable_id`).
- **professionals** e **services** são N:N via **professional_service**. A pivô carrega `company_id`, e as FKs compostas garantem que profissional e serviço sejam da mesma empresa.
- Um **booking** pertence a uma **company**, a um **customer** (FK composta `(company_id, customer_id)`) e a um par **professional/service** (FK composta `(company_id, professional_id, service_id)` → `professional_service`).
- Um **booking** pertence a um **booking_status**.

## Lookup Table Seeds

- **booking_statuses**, com IDs **fixos**, seedados pela própria migration para existirem antes de qualquer booking:
  - `1` — name `Confirmado`, slug `confirmed`
  - `2` — name `Cancelado`, slug `cancelled`

## Notes & Conventions

- **Sem soft deletes** em nenhuma tabela. O cancelamento de booking é feito por `status_id = 2`.
- **IDs fixos em `booking_statuses`.** A exclusion constraint só aceita predicado imutável (`WHERE status_id = 1`), sem subquery na lookup. Por isso os IDs são contrato e devem existir como constantes no código, por exemplo um enum PHP `BookingStatus: int { Confirmed = 1; Cancelled = 2 }` usado apenas na aplicação (não é coluna enum no banco). O seed fica na migration, não no `DatabaseSeeder`, para que testes com `RefreshDatabase` já tenham os status.
- **Status unificado nos três documentos.** O booking guarda `status_id` (FK para `booking_statuses`). Um status inválido falha com `23503` (US-5.1). A exclusion constraint usa `WHERE (status_id = 1)`. A API expõe o slug (`"status": "confirmed"`).
- **Constraints criadas via SQL bruto** (`DB::statement`) nas migrations, porque o Blueprint não as expressa:
  - `CREATE EXTENSION IF NOT EXISTS btree_gist`;
  - `bookings_no_overlap`: a `EXCLUDE USING gist` acima (US-2.1, US-2.2);
  - `bookings_ends_after_starts`: `CHECK (ends_at > starts_at)` (US-5.1);
  - `services_duration_positive`: `CHECK (duration_minutes > 0)` (US-5.1);
  - as FKs compostas (seção `Ref:` acima), criadas com `$table->foreign(['company_id', 'customer_id'])->references(['company_id', 'id'])->on('customers')` ou via SQL.
- **`UNIQUE (company_id, id)`** em `professionals`, `customers` e `services` é redundante como unicidade (`id` já é PK), mas é exigido pelo PostgreSQL como alvo das FKs compostas (US-3.2, US-5.1).
- **`bookings.professional_id` e `bookings.service_id`** não têm FK simples própria. A FK composta para `professional_service` já garante a existência de ambos, a mesma empresa e o vínculo profissional/serviço (US-1.3).
- **`ends_at` é desnormalizado** a partir de `services.duration_minutes` no momento da criação e funciona como snapshot. Alterar a duração do serviço não afeta bookings existentes (US-1.3).
- **`timestamptz`** em `starts_at`/`ends_at` (`$table->timestampTz()`), porque `tstzrange` e a entrada com offset exigem fuso. As demais colunas de timestamp seguem o padrão Laravel (`timestamp`).
- **Índices:**
  - o GiST da exclusion constraint já atende a busca por sobreposição;
  - `(professional_id, starts_at)` serve consultas de agenda futuras;
  - os índices `(company_id, …)` cobrem as FKs compostas.
- **`users`** estende a migration existente (`0001_01_01_000000_create_users_table.php`) com `company_id`. As tabelas `password_reset_tokens`, `sessions`, `cache` e `jobs` do skeleton permanecem e não fazem parte do domínio.
- **`personal_access_tokens`** é a tabela padrão do Sanctum. O tenant é resolvido por `users.company_id`, não pelo token. Tokens de demonstração são emitidos só em ambiente `local`, com nome `demo` e `expires_at` de 7 dias (US-4.1). O schema não muda por isso.
- **Banco de testes dedicado.** Os testes rodam em `agendamento_testing`, no mesmo PostgreSQL, com o mesmo schema, `btree_gist` e o seed de `booking_statuses` via migration. O teste de concorrência faz commits reais nesse banco, cria uma empresa exclusiva por execução e apaga os dados dela no `tearDown`. A FK `restrict` em bookings exige apagar os bookings antes de clientes e da pivô (US-2.2).
- **Deletes:** cascade na pivô quando o profissional ou o serviço é removido; restrict em bookings, para não apagar histórico. Não há endpoints de exclusão no MVP.
- **Conceitos não persistidos:**
  - **Intervalo de horário** — persistido como o par `starts_at`/`ends_at` em `bookings`; o `tstzrange` é derivado na constraint.
  - **Conflito de horário** — regra derivada, imposta pela exclusion constraint.
  - **Integridade multi-tenant no banco**, **Invariantes protegidas pelo banco** e **Exclusion constraint** — são constraints deste schema, não tabelas.

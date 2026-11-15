# Agendamento — User Stories

<!-- inputs: project-description.md@sha256:269de7f9cebe -->

## Overview

**Agendamento** é uma API multi-tenant de estudo para reservar horários de profissionais. O escopo é um único endpoint, `POST /api/bookings`, que reserva um serviço com um profissional para um cliente. O término do agendamento é calculado pela duração do serviço. Várias empresas compartilham a aplicação com dados isolados por `company_id`, e o próprio banco reforça esse isolamento com chaves estrangeiras compostas. A regra central é que um profissional nunca tenha dois agendamentos `confirmed` sobrepostos, inclusive sob concorrência entre servidores. Empresas, usuários, profissionais, clientes e serviços já existem no banco e são criados por seeders; não há endpoints de cadastro, listagem, cancelamento ou login.

**User Types:**
- **Usuário da empresa** - consumidor autenticado da API (token Sanctum), pertencente a exatamente uma empresa; cria agendamentos somente para profissionais e clientes da própria empresa.
- **Desenvolvedor** - quem roda o projeto localmente; precisa de dados de demonstração e de tokens válidos para exercitar a API.

---

## 1. Criação de agendamentos

### US-1.1: Criar um agendamento válido
**As a** usuário da empresa
**I want to** enviar `POST /api/bookings` com `professional_id`, `customer_id`, `service_id` e `starts_at`
**So that** o horário do profissional fique reservado para o cliente no serviço escolhido

**Acceptance Criteria:**
- [ ] A requisição com Bearer token válido e payload válido retorna `201 Created`.
- [ ] O corpo da resposta traz `data` com `id`, `professional_id`, `customer_id`, `service_id`, `starts_at`, `ends_at`, `status` e `created_at`.
- [ ] O booking é persistido com `status_id = 1`, e a resposta expõe o slug: `"status": "confirmed"`.
- [ ] As datas da resposta são serializadas em ISO 8601 UTC (ex.: `2026-11-20T17:00:00Z`), mesmo quando a entrada vem com offset (ex.: `-03:00`).
- [ ] O booking é persistido com o `company_id` do usuário autenticado.
- [ ] Um `company_id` enviado no payload é ignorado e não altera o tenant do booking.

**Expected Result:** O booking existe no banco como `confirmed` (`status_id = 1`), vinculado à empresa do usuário, e a API devolveu `201` com o recurso criado.

---

### US-1.2: Validar o payload do agendamento
**As a** usuário da empresa
**I want to** receber erros de validação claros quando o payload for inválido
**So that** eu saiba exatamente o que corrigir antes de tentar de novo

**Acceptance Criteria:**
- [ ] A ausência de qualquer um dos campos `professional_id`, `customer_id`, `service_id` ou `starts_at` retorna `422`, com o erro no campo correspondente.
- [ ] `professional_id`, `customer_id` ou `service_id` não inteiro retorna `422`.
- [ ] `professional_id`, `customer_id` ou `service_id` inexistente retorna `422`.
- [ ] `starts_at` fora do formato ISO 8601 retorna `422`.
- [ ] `starts_at` no passado retorna `422`. O teste congela o relógio (`freezeTime`/`travelTo`) e usa `now()->subMinute()` como passado e `now()->addMinute()` como futuro válido.
- [ ] Nenhum booking é persistido quando a validação falha.

**Expected Result:** Um payload inválido nunca gera booking; a API responde `422` com o formato padrão de erros de validação do Laravel (`message` + `errors`).

---

### US-1.3: Calcular o término pelo serviço
**As a** usuário da empresa
**I want to** que o horário de término seja calculado pela duração do serviço
**So that** eu não precise informar `ends_at` e a agenda reflita o tempo real de cada serviço

**Acceptance Criteria:**
- [ ] Com o relógio congelado em `2026-01-01T12:00:00Z`, um serviço com `duration_minutes = 45` e `starts_at = 2026-01-02T14:00:00-03:00` gera um booking com `ends_at = 2026-01-02T17:45:00Z`. No teste, o `starts_at` é relativo ao relógio congelado (`now()->addDay()`), nunca uma data absoluta que vence com o tempo.
- [ ] Um `ends_at` enviado no payload é ignorado; o valor persistido é sempre `starts_at + duration_minutes`.
- [ ] Um `service_id` válido da empresa que o profissional não executa (sem registro na pivô professional ↔ service) retorna `422`, com erro em `service_id`.
- [ ] Alterar `duration_minutes` do serviço depois da criação não altera o `ends_at` de bookings existentes.

**Expected Result:** Cada booking tem o término calculado no momento da criação pela duração do serviço, e só é criado se o profissional executa aquele serviço.

---

## 2. Conflito de horários

### US-2.1: Impedir agendamentos sobrepostos do mesmo profissional
**As a** usuário da empresa
**I want to** ser impedido de reservar um profissional em um horário já ocupado
**So that** o profissional nunca tenha dois atendimentos ao mesmo tempo

**Acceptance Criteria:**
- [ ] Os horários abaixo são do dia seguinte ao relógio congelado do teste (`now()->addDay()->setTime(...)`).
- [ ] Já existe um booking `confirmed` de 14:00 a 15:00 (serviço de 60 minutos). Um novo booking do mesmo profissional retorna `409 Conflict` nos três casos de sobreposição:
  - sobreposição parcial: serviço de 60 minutos iniciando às 14:30 ou às 13:30;
  - intervalo idêntico: serviço de 60 minutos iniciando às 14:00;
  - intervalo contido ou que contém: serviço de 30 minutos iniciando às 14:15, ou serviço de 180 minutos iniciando às 13:00.
- [ ] O corpo do `409` contém `message: "O profissional já possui um agendamento nesse horário."`.
- [ ] Intervalos adjacentes não conflitam: com o booking de 14:00 a 15:00 existente, um serviço de 60 minutos iniciando às 15:00 ou às 13:00 retorna `201`.
- [ ] Um booking `cancelled` (`status_id = 2`) não bloqueia o horário: um novo booking sobreposto a ele retorna `201`.
- [ ] Bookings sobrepostos de profissionais diferentes são permitidos e retornam `201`.
- [ ] A regra é imposta por uma exclusion constraint do PostgreSQL (`btree_gist`, `tstzrange '[)'`, `WHERE status_id = 1`), e a aplicação traduz o SQLSTATE `23P01` em `409`.

**Expected Result:** Nenhum profissional possui dois bookings `confirmed` com intervalos que se intersectam; tentativas conflitantes recebem `409` e não são persistidas.

---

### US-2.2: Garantir uma única reserva sob requisições concorrentes
**As a** usuário da empresa
**I want to** que apenas uma de duas requisições simultâneas para o mesmo profissional e horário seja confirmada
**So that** a agenda permaneça consistente mesmo com múltiplos servidores atendendo a API

**Acceptance Criteria:**
- [ ] Com duas transações concorrentes inserindo bookings sobrepostos do mesmo profissional, exatamente uma é confirmada e a outra falha com `23P01`, que vira `409`.
- [ ] A garantia não depende de lock em memória, cache, Redis ou checagem prévia na aplicação; depende apenas da constraint no banco compartilhado.
- [ ] Existe um teste que comprova que o banco rejeita o segundo insert sobreposto quando a checagem da aplicação é contornada (insert direto, sem passar pela validação).
- [ ] Existe um **teste de concorrência real**:
  - o teste dispara 5 workers com `Process::pool`, cada um chamando a Action `CreateBooking` com o mesmo profissional, serviço e `starts_at`;
  - os workers largam juntos: o orquestrador segura `pg_advisory_lock` e cada worker espera em `pg_advisory_lock_shared` até a liberação;
  - o teste exige exatamente 1 `created`, 4 `conflict` e 0 `error`, e exatamente 1 booking `confirmed` no banco.
- [ ] Timeouts:
  - cada processo tem timeout de 15 s, e cada worker usa `lock_timeout` e `statement_timeout` de 5 s;
  - o advisory lock é liberado num `finally`;
  - qualquer timeout faz o teste falhar.
- [ ] Isolamento do banco:
  - o teste roda no banco dedicado `agendamento_testing`, repassado aos workers por variável de ambiente;
  - ele não usa `RefreshDatabase`, porque precisa de commits reais; cria uma empresa exclusiva e apaga os dados dela no `tearDown`;
  - ele fica em `#[Group('concurrency')]` e nunca roda em paralelo com outros testes.
- [ ] O `starts_at` é calculado pelo orquestrador e repassado aos workers, já que processos filhos não herdam o relógio congelado.

**Expected Result:** Independentemente de qual servidor processe cada requisição, só um booking sobreposto é gravado como `confirmed`.

---

## 3. Autenticação e isolamento de tenant

### US-3.1: Exigir autenticação
**As a** usuário da empresa
**I want to** que o endpoint só aceite chamadas com token Sanctum válido
**So that** apenas usuários identificados consigam criar agendamentos

**Acceptance Criteria:**
- [ ] A requisição sem header `Authorization` retorna `401`.
- [ ] A requisição com token inválido ou revogado retorna `401`.
- [ ] Nenhum booking é persistido em respostas `401`.

**Expected Result:** O endpoint `POST /api/bookings` está protegido por `auth:sanctum`, e chamadas anônimas são rejeitadas.

---

### US-3.2: Impedir uso de recursos de outra empresa
**As a** usuário da empresa
**I want to** ser impedido de agendar profissionais ou clientes que não pertencem à minha empresa
**So that** os dados de cada empresa permaneçam isolados

**Acceptance Criteria:**
- [ ] Um usuário da empresa A que envia o `professional_id` de um profissional da empresa B recebe `422`, com erro em `professional_id`.
- [ ] Um usuário da empresa A que envia o `customer_id` de um cliente da empresa B recebe `422`, com erro em `customer_id`.
- [ ] Um usuário da empresa A que envia o `service_id` de um serviço da empresa B recebe `422`, com erro em `service_id`.
- [ ] A mensagem de erro para o ID de outra empresa é idêntica à mensagem para um ID inexistente, então a resposta não revela que o recurso existe.
- [ ] O `company_id` do booking é sempre o do usuário autenticado, nunca o do request.
- [ ] Nenhum booking é persistido nesses casos.

**Expected Result:** Um usuário só consegue criar bookings que combinem profissional, cliente e serviço da própria empresa; tentativas cross-tenant são indistinguíveis de IDs inexistentes.

---

## 4. Ambiente de desenvolvimento

### US-4.1: Popular dados de demonstração com tokens
**As a** desenvolvedor
**I want to** rodar um seeder que cria empresas, usuários, profissionais, clientes e serviços e emite um token Sanctum por usuário
**So that** eu consiga exercitar `POST /api/bookings` localmente sem endpoints de cadastro ou login

**Acceptance Criteria:**
- [ ] `php artisan db:seed` cria pelo menos 2 empresas, cada uma com pelo menos 1 usuário, 2 profissionais, 2 clientes e 2 serviços com durações diferentes.
- [ ] O seeder vincula os serviços aos profissionais pela pivô, com ao menos um par profissional/serviço **não** vinculado, para testar o `422`.
- [ ] Tokens só são emitidos quando `app()->environment('local')`. Em qualquer outro ambiente (`testing`, `production`, …), o `DemoSeeder` aborta sem criar tokens, e há um teste para isso.
- [ ] Os tokens se chamam `demo` e expiram em 7 dias (`expires_at`).
- [ ] O texto plano de cada token é exibido **uma única vez** no console, numa tabela com usuário e empresa. Não é gravado em arquivo nem em log.
- [ ] Os tokens exibidos autenticam com sucesso em `POST /api/bookings`.
- [ ] Factories existem para Company, User, Professional, Customer, Service e Booking, para uso nos testes.

**Expected Result:** Depois de `migrate --seed`, o desenvolvedor tem tokens de ao menos duas empresas e IDs de profissionais, clientes e serviços para testar, inclusive o isolamento entre tenants.

---

## 5. Integridade no banco de dados

### US-5.1: Proteger as invariantes no banco
**As a** desenvolvedor
**I want to** que o PostgreSQL rejeite dados inválidos mesmo quando a validação Laravel é contornada
**So that** a consistência multi-tenant e de horários não dependa apenas da camada de aplicação

**Acceptance Criteria:**
- [ ] `professionals`, `customers` e `services` têm `UNIQUE (company_id, id)`.
- [ ] Insert direto em `bookings` com cliente de outra empresa falha com `23503` (FK composta `(company_id, customer_id)`).
- [ ] Insert direto em `bookings` com profissional ou serviço de outra empresa, ou com serviço que o profissional não executa, falha com `23503` (FK composta `(company_id, professional_id, service_id)` → `professional_service`).
- [ ] Insert direto em `professional_service` com profissional e serviço de empresas diferentes falha com `23503`.
- [ ] Insert direto em `bookings` com `ends_at <= starts_at` falha com `23514`.
- [ ] Insert direto em `bookings` com `status_id` inexistente em `booking_statuses` falha com `23503`.
- [ ] `booking_statuses` contém exatamente os IDs fixos `1 = confirmed` e `2 = cancelled`, seedados pela migration, e o enum PHP `BookingStatus` espelha esses IDs.
- [ ] Insert direto em `services` com `duration_minutes <= 0` falha com `23514`.
- [ ] Colunas de negócio são `NOT NULL`; insert com qualquer uma nula falha com `23502`.
- [ ] Cada invariante tem um teste que insere pelo query builder, sem Form Request, e verifica o SQLSTATE.
- [ ] Na API, só a `23P01` é tratada (vira `409`); qualquer outra violação que escape da validação resulta em `500`.

**Expected Result:** O schema impõe isolamento entre empresas, vínculo profissional ↔ serviço, intervalo válido, status válido, duração positiva e não sobreposição, independentemente de quem escreve no banco.

---

## Appendix: User Story Status

| ID | Story | Priority | Status |
|----|-------|----------|--------|
| US-1.1 | Criar um agendamento válido | High | Done |
| US-2.1 | Impedir agendamentos sobrepostos do mesmo profissional | High | Done |
| US-2.2 | Garantir uma única reserva sob requisições concorrentes | High | Done |
| US-3.1 | Exigir autenticação | High | Done |
| US-3.2 | Impedir uso de recursos de outra empresa | High | Done |
| US-1.2 | Validar o payload do agendamento | High | Done |
| US-4.1 | Popular dados de demonstração com tokens | Medium | Done |
| US-1.3 | Calcular o término pelo serviço | High | Done |
| US-5.1 | Proteger as invariantes no banco | High | Done |

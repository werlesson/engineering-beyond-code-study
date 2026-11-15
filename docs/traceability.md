# Rastreabilidade: critérios de aceitação → testes

Checklist que liga cada critério de aceitação de `.spec/init/user-stories.md` a pelo menos um teste
nomeado. Os testes ficam em `tests/Feature/` (os de concorrência em `tests/Feature/Concurrency/`) e rodam
contra o PostgreSQL de testes (`agendamento_testing`) com `composer test`.

Notação: `Classe::método`. Métodos com `#[DataProvider]` cobrem todos os casos do provider.

## US-1.1: Criar um agendamento válido

| Critério de aceitação | Teste(s) |
|---|---|
| Bearer token válido e payload válido retornam `201 Created` | `CreateBookingTest::test_valid_request_returns_201_with_resource` |
| `data` traz `id`, `professional_id`, `customer_id`, `service_id`, `starts_at`, `ends_at`, `status` e `created_at` | `CreateBookingTest::test_valid_request_returns_201_with_resource` |
| Persistido com `status_id = 1`, resposta expõe `"status": "confirmed"` | `CreateBookingTest::test_booking_is_persisted_confirmed` |
| Datas da resposta em ISO 8601 UTC, mesmo com entrada em offset | `CreateBookingTest::test_dates_are_serialized_in_utc`, `StoreBookingValidationTest::test_starts_at_accepts_offset_and_zulu`, `BookingModelTest::test_dates_read_from_database_are_utc` |
| Persistido com o `company_id` do usuário autenticado | `CreateBookingTest::test_booking_belongs_to_authenticated_user_company` |
| `company_id` no payload é ignorado | `TenantIsolationTest::test_company_id_in_payload_is_ignored` |

## US-1.2: Validar o payload do agendamento

| Critério de aceitação | Teste(s) |
|---|---|
| Ausência de `professional_id`, `customer_id`, `service_id` ou `starts_at` retorna `422` no campo | `StoreBookingValidationTest::test_each_required_field_missing_returns_422` |
| IDs não inteiros retornam `422` | `StoreBookingValidationTest::test_non_integer_ids_return_422` |
| IDs inexistentes retornam `422` | `StoreBookingValidationTest::test_nonexistent_ids_return_422` |
| `starts_at` fora do ISO 8601 retorna `422` | `StoreBookingValidationTest::test_invalid_starts_at_format_returns_422` |
| `starts_at` no passado retorna `422`, com relógio congelado (`subMinute`/`addMinute`) | `StoreBookingValidationTest::test_starts_at_in_the_past_returns_422`, `StoreBookingValidationTest::test_starts_at_just_in_the_future_is_accepted`, `ClockConventionTest::test_freeze_clock_pins_now` |
| Nenhum booking persistido quando a validação falha | todos os testes `422` de `StoreBookingValidationTest` (helper `assertRejected` verifica `assertDatabaseCount('bookings', 0)`) |

## US-1.3: Calcular o término pelo serviço

| Critério de aceitação | Teste(s) |
|---|---|
| Serviço de 45 min com `starts_at` relativo ao relógio congelado gera `ends_at = starts_at + 45 min` em UTC | `CreateBookingTest::test_ends_at_is_starts_at_plus_service_duration`, `CreateBookingActionTest::test_creates_confirmed_booking_with_computed_ends_at` |
| `ends_at` enviado no payload é ignorado | `CreateBookingTest::test_ends_at_in_payload_is_ignored` |
| Serviço da empresa não executado pelo profissional retorna `422` em `service_id` | `TenantIsolationTest::test_service_not_offered_by_professional_returns_422_on_service_id` |
| Alterar `duration_minutes` depois não altera `ends_at` existentes | `CreateBookingTest::test_changing_service_duration_does_not_change_existing_bookings` |

## US-2.1: Impedir agendamentos sobrepostos do mesmo profissional

| Critério de aceitação | Teste(s) |
|---|---|
| Horários relativos ao dia seguinte do relógio congelado | todos os testes de `BookingConflictTest` (`freezeClock()` no `setUp`, helper `tomorrowAt`) |
| Sobreposição parcial (14:30 e 13:30) retorna `409` | `BookingConflictTest::test_partial_overlap_returns_409` |
| Intervalo idêntico (14:00) retorna `409` | `BookingConflictTest::test_identical_interval_returns_409` |
| Intervalo contido (30 min às 14:15) ou que contém (180 min às 13:00) retorna `409` | `BookingConflictTest::test_contained_and_containing_intervals_return_409` |
| Corpo do `409` com a mensagem exata | `BookingConflictTest::test_conflict_response_body_has_expected_message` |
| Intervalos adjacentes (15:00 e 13:00) retornam `201` | `BookingConflictTest::test_adjacent_intervals_return_201`, `ExclusionConstraintTest::test_adjacent_bookings_are_allowed` |
| Booking `cancelled` não bloqueia o horário | `BookingConflictTest::test_overlap_with_cancelled_booking_returns_201`, `ExclusionConstraintTest::test_cancelled_booking_does_not_block_slot` |
| Profissionais diferentes podem se sobrepor | `BookingConflictTest::test_overlap_with_other_professional_returns_201`, `ExclusionConstraintTest::test_overlap_for_different_professionals_is_allowed` |
| Regra imposta por exclusion constraint (`btree_gist`, `tstzrange '[)'`, `WHERE status_id = 1`) | `DatabaseSchemaTest::test_bookings_no_overlap_is_an_exclusion_constraint`, `DatabaseSchemaTest::test_btree_gist_extension_is_enabled_after_migration`, `ExclusionConstraintTest::test_overlapping_confirmed_bookings_same_professional_fail` |
| A aplicação traduz o SQLSTATE `23P01` em `409` | `BookingConflictTest::test_exclusion_violation_raised_by_database_returns_409`, `NoApplicationLevelCheckTest::test_action_throws_conflict_exception_on_second_overlapping_insert` |

## US-2.2: Garantir uma única reserva sob requisições concorrentes

| Critério de aceitação | Teste(s) |
|---|---|
| Duas transações concorrentes sobrepostas: uma confirma, a outra vira `409` | `Concurrency\ConcurrentBookingTest::test_only_one_of_five_simultaneous_overlapping_bookings_is_confirmed`, `Concurrency\ConcurrentBookingHttpTest::test_five_simultaneous_http_requests_yield_one_201_and_four_409` |
| Sem lock em memória, cache, Redis ou checagem prévia na aplicação | `NoApplicationLevelCheckTest::test_create_booking_issues_no_overlap_select_before_insert` |
| O banco rejeita o segundo insert sobreposto quando a aplicação é contornada | `ExclusionConstraintTest::test_overlapping_confirmed_bookings_same_professional_fail`, `ExclusionConstraintTest::test_reconfirming_cancelled_booking_over_confirmed_fails` |
| Teste de concorrência real: 5 workers via `Process::pool` chamando `CreateBooking`, largada por `pg_advisory_lock`/`pg_advisory_lock_shared`, exatamente 1 `created`, 4 `conflict`, 0 `error` e 1 booking `confirmed` | `Concurrency\ConcurrentBookingTest::test_only_one_of_five_simultaneous_overlapping_bookings_is_confirmed` |
| Timeouts: por processo, `lock_timeout`/`statement_timeout` de 5 s no insert, advisory lock liberado em `finally`, timeout falha o teste | `Concurrency\ConcurrentBookingTest::test_only_one_of_five_simultaneous_overlapping_bookings_is_confirmed` (ver nota abaixo) |
| Banco dedicado `agendamento_testing` repassado aos workers | `TestDatabaseConfigurationTest::test_tests_run_on_dedicated_postgres_database`, `ProcessDatabaseGuardTest::test_worker_guard_exits_with_unsafe_database_output_for_other_database`, `ProcessDatabaseGuardTest::test_worker_guard_lets_agendamento_testing_continue`, `SafeTestDatabaseTest::test_guard_rejects_non_testing_database` |
| Sem `RefreshDatabase`; empresa exclusiva apagada no `tearDown`; `#[Group('concurrency')]` | `Concurrency\ConcurrentBookingTest` e `Concurrency\ConcurrentBookingHttpTest` (trait `Tests\Support\ConcurrencyScenario`) |
| `starts_at` calculado pelo orquestrador e repassado aos workers | `Concurrency\ConcurrentBookingTest::test_only_one_of_five_simultaneous_overlapping_bookings_is_confirmed` |

**Nota sobre o timeout por processo:** a user story fala em 15 s por processo. O plano de implementação
(`.spec/init/project-phases.md`, Phase 5.2) refinou o orçamento de tempo do worker: barreira do orquestrador
≤ 10 s < `lock_timeout` de 15 s na barreira do worker; insert com `lock_timeout` e `statement_timeout` de 5 s;
timeout total de 30 s por processo. O teste HTTP concorrente usa 15 s por requisição. Qualquer timeout faz o
teste falhar.

**Não verificável automaticamente:** "nunca roda em paralelo com outros testes" é garantido pelo grupo
`concurrency` e documentado no `README.md` (nunca usar `--parallel` com esse grupo), assim como a checagem
manual de estabilidade:

```bash
for i in 1 2 3 4 5; do php artisan test --group=concurrency || break; done
```

## US-3.1: Exigir autenticação

| Critério de aceitação | Teste(s) |
|---|---|
| Sem header `Authorization` retorna `401` | `BookingAuthenticationTest::test_request_without_authorization_header_returns_401` |
| Token inválido ou revogado retorna `401` | `BookingAuthenticationTest::test_request_with_invalid_token_returns_401`, `BookingAuthenticationTest::test_request_with_revoked_token_returns_401`, `BookingAuthenticationTest::test_request_with_expired_token_returns_401` |
| Nenhum booking persistido em `401` | todos os testes `401` de `BookingAuthenticationTest` (`assertDatabaseCount('bookings', 0)`) |

## US-3.2: Impedir uso de recursos de outra empresa

| Critério de aceitação | Teste(s) |
|---|---|
| `professional_id` de outra empresa retorna `422` em `professional_id` | `TenantIsolationTest::test_professional_from_other_company_returns_422_on_professional_id` |
| `customer_id` de outra empresa retorna `422` em `customer_id` | `TenantIsolationTest::test_customer_from_other_company_returns_422_on_customer_id` |
| `service_id` de outra empresa retorna `422` em `service_id` | `TenantIsolationTest::test_service_from_other_company_returns_422_on_service_id` |
| Mensagem idêntica à de ID inexistente | `TenantIsolationTest::test_cross_tenant_error_message_equals_nonexistent_id_message` |
| `company_id` do booking é sempre o do usuário | `TenantIsolationTest::test_company_id_in_payload_is_ignored`, `CreateBookingTest::test_booking_belongs_to_authenticated_user_company` |
| Nenhum booking persistido nesses casos | testes `422` de `TenantIsolationTest` (`assertDatabaseCount('bookings', 0)`) |

## US-4.1: Popular dados de demonstração com tokens

| Critério de aceitação | Teste(s) |
|---|---|
| Pelo menos 2 empresas, cada uma com ≥ 1 usuário, 2 profissionais, 2 clientes e 2 serviços de durações diferentes | `DemoSeederTest::test_seeder_creates_two_companies_with_full_dataset` |
| Pelo menos um par profissional/serviço não vinculado | `DemoSeederTest::test_seeder_leaves_at_least_one_unlinked_professional_service_pair` |
| Tokens só em `local`; em outro ambiente o `DemoSeeder` aborta sem tokens | `DemoSeederTest::test_seeder_aborts_outside_local_environment` |
| Tokens chamados `demo`, expirando em 7 dias | `DemoSeederTest::test_tokens_are_named_demo_and_expire_in_seven_days` |
| Texto plano exibido uma única vez no console, sem arquivo ou log | `DemoSeederTest::test_printed_token_authenticates_booking_endpoint` (exatamente 2 tokens na saída), `DemoSeederTest::test_tokens_are_not_logged` |
| Os tokens exibidos autenticam em `POST /api/bookings` | `DemoSeederTest::test_printed_token_authenticates_booking_endpoint` |
| Factories de Company, User, Professional, Customer, Service e Booking | `FactoriesIntegrityTest` (todos os métodos, em especial `test_professional_customer_and_service_factories_create_company`, `test_user_factory_creates_user_with_company` e `test_booking_factory_satisfies_all_constraints`) |

## US-5.1: Proteger as invariantes no banco

| Critério de aceitação | Teste(s) |
|---|---|
| `UNIQUE (company_id, id)` em `professionals`, `customers` e `services` | `DatabaseSchemaTest::test_tenant_tables_expose_company_id_id_unique_key` |
| Cliente de outra empresa em `bookings` falha com `23503` | `TenantIntegrityTest::test_booking_with_customer_from_other_company_fails` |
| Profissional/serviço de outra empresa ou serviço não executado falha com `23503` | `TenantIntegrityTest::test_booking_with_professional_from_other_company_fails`, `TenantIntegrityTest::test_booking_with_service_from_other_company_fails`, `TenantIntegrityTest::test_booking_with_service_not_offered_by_professional_fails` |
| `professional_service` com empresas diferentes falha com `23503` | `TenantIntegrityTest::test_pivot_with_professional_and_service_from_different_companies_fails`, `ProfessionalModelTest::test_offer_service_from_other_company_is_rejected_by_database` |
| `ends_at <= starts_at` falha com `23514` | `DatabaseInvariantsTest::test_booking_with_ends_at_equal_to_starts_at_fails`, `DatabaseInvariantsTest::test_booking_with_ends_at_before_starts_at_fails` |
| `status_id` inexistente falha com `23503` | `DatabaseInvariantsTest::test_booking_with_unknown_status_id_fails` |
| `booking_statuses` com IDs fixos `1 = confirmed`, `2 = cancelled`, espelhados pelo enum | `BookingStatusesSeedTest::test_fixed_status_ids_exist_after_migration`, `BookingStatusEnumTest::test_enum_mirrors_booking_statuses_table` |
| `duration_minutes <= 0` falha com `23514` | `DatabaseInvariantsTest::test_service_with_zero_or_negative_duration_fails` |
| Colunas de negócio `NOT NULL` (`23502`) | `DatabaseInvariantsTest::test_null_business_columns_fail` |
| Cada invariante testada por insert direto no query builder, verificando o SQLSTATE | `DatabaseInvariantsTest`, `TenantIntegrityTest`, `ExclusionConstraintTest` (via `Tests\Support\RawFixtures`; o helper é validado por `RawFixturesTest`) |
| Na API só a `23P01` vira `409`; outras violações resultam em `500` | `UnexpectedDatabaseErrorTest::test_non_overlap_query_exception_returns_500`, `UnexpectedDatabaseErrorTest::test_action_rethrows_non_overlap_query_exception_untouched`, `BookingConflictTest::test_exclusion_violation_raised_by_database_returns_409` |

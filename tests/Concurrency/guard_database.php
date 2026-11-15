<?php

/**
 * Incluído no topo dos processos filhos dos testes (ex.: create_booking_worker.php),
 * antes do bootstrap da aplicação e de qualquer escrita no banco.
 *
 * Se o DB_DATABASE recebido pelo ambiente não for `agendamento_testing`, imprime
 * {"result":"error","message":"unsafe database"} e encerra com exit code 0.
 */

use Tests\Support\ProcessDatabaseGuard;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

ProcessDatabaseGuard::guardCurrentProcess();

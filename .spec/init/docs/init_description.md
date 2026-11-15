Requisitos funcionais

RF01 — Multi-tenancy
O sistema deve permitir que diferentes empresas utilizem a mesma aplicação, mantendo seus dados isolados.
Cada empresa terá seus próprios profissionais e agendamentos.

RF02 — Cadastro de profissionais
Cada profissional deve pertencer a uma empresa.
Um profissional poderá receber diferentes agendamentos ao longo do dia.

RF03 — Criar agendamentos
Implemente o endpoint: POST /api/bookings
A API deverá receber os dados necessários para registrar um agendamento, como profissional, cliente e intervalo de horário.
Você será responsável por definir o contrato da API.

RF04 — Impedir conflitos de horários
Um profissional não poderá ter dois agendamentos ativos com horários sobrepostos.

RF05 — Concorrência
Duas requisições simultâneas tentam reservar o mesmo profissional no mesmo horário.
O sistema deve garantir que somente uma reserva seja confirmada, mesmo que as requisições sejam processadas por servidores diferentes.

RF06 — Autenticação e autorização
A API deve autenticar quem realiza a operação e impedir que alguém acesse ou manipule recursos de outra empresa sem autorização.

Quero simplicidade e clareza na implementação, garantindo que os requisitos funcionais sejam atendidos de forma eficiente e segura.

Leve em consideração o clean code, boas práticas de desenvolvimento e padrões de projeto ao implementar a solução.

Isso é para a implementação de somente este endpoint citado, entao não é necessário criar endpoints para cadastro de empresas, profissionais ou clientes. Você pode assumir que esses dados já existem no sistema e estão disponíveis para uso.

Isso é somente para estudo e estou implementando uma funcionalidade de agendamento de profissionais, então não é necessário criar endpoints para cadastro de empresas, profissionais ou clientes. Você pode assumir que esses dados já existem no sistema e estão disponíveis para uso.

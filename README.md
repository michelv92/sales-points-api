# Sales Points API

API desenvolvida em Laravel para recebimento e importação de vendas, com processamento assíncrono da pontuação dos clientes.

Cada R$ 10,00 em vendas corresponde a 1 ponto. Somente pontos inteiros são considerados:

- R$ 350,00 → 35 pontos
- R$ 355,00 → 35 pontos

## Tecnologias

- PHP 8.5
- Laravel 12
- MySQL 8.4
- Redis 7
- Nginx
- Docker Compose
- Laravel Sanctum
- PHPUnit

## Requisitos

- Docker
- Docker Compose

Não é necessário possuir PHP, Composer, MySQL ou Redis instalados localmente.

## Instalação

Clone o repositório e entre no diretório:

```bash
git clone https://github.com/michelv92/sales-points-api.git
cd sales-points-api
```

Crie o arquivo de ambiente:

```bash
cp .env.example .env
```

Confira seu UID e GID:

```bash
id -u
id -g
```

Caso sejam diferentes de `1000`, altere `LOCAL_UID` e `LOCAL_GID` no `.env`.

Defina um segredo próprio para assinatura dos webhooks:

```dotenv
WEBHOOK_SECRET=change-this-secret
```

Construa a imagem PHP:

```bash
docker compose build
```

Instale as dependências:

```bash
docker compose run --rm app composer install
```

Gere a chave da aplicação:

```bash
docker compose run --rm app php artisan key:generate
```

Suba os serviços:

```bash
docker compose up -d
```

Execute as migrations:

```bash
docker compose exec app php artisan migrate
```

A aplicação estará disponível em:

```text
http://localhost:8080
```

## Serviços Docker

| Serviço | Responsabilidade |
|---|---|
| `app` | PHP-FPM e aplicação Laravel |
| `nginx` | Servidor HTTP |
| `mysql` | Persistência principal |
| `redis` | Filas, cache e locks |
| `queue` | Processamento dos Jobs |
| `scheduler` | Execução das tarefas agendadas |

Verifique os containers:

```bash
docker compose ps
```

## Execução das filas

O worker é iniciado automaticamente pelo Docker Compose:

```bash
docker compose logs -f queue
```

Execução manual, caso necessário:

```bash
docker compose exec app php artisan queue:work redis \
    --sleep=1 \
    --tries=3 \
    --timeout=1200
```

## Scheduler

O scheduler também é iniciado automaticamente:

```bash
docker compose logs -f scheduler
```

Ele procura periodicamente vendas que permaneceram pendentes e as envia novamente para a fila:

```bash
docker compose exec app php artisan sales:redispatch-pending
```

Para tentar novamente vendas marcadas como falha:

```bash
docker compose exec app php artisan sales:redispatch-pending \
    --include-failed
```

## Testes automatizados

A suíte usa SQLite em memória e não altera o banco MySQL de desenvolvimento:

```bash
docker compose exec app php artisan test
```

Validação de estilo:

```bash
docker compose exec app vendor/bin/pint --test
```

Os testes cobrem, entre outros cenários:

- webhook válido;
- assinatura inválida;
- payload inválido;
- venda duplicada;
- conflito de dados para o mesmo `external_id`;
- cálculo da pontuação;
- descarte de frações;
- execução duplicada do Job;
- acúmulo de vendas para o mesmo cliente;
- autenticação da consulta de saldo;
- upload de CSV;
- linha inválida sem interromper o arquivo;
- cabeçalho CSV inválido;
- duplicidade entre CSV e webhook;
- auditoria das linhas importadas.

## Autenticação da API

As rotas de consulta e importação utilizam Laravel Sanctum.

Para criar um usuário e token de desenvolvimento:

```bash
docker compose exec app php artisan tinker --execute='
$user = App\Models\User::query()->firstOrCreate(
    ["email" => "admin@example.com"],
    [
        "name" => "Admin",
        "password" => Illuminate\Support\Facades\Hash::make("password"),
    ],
);

dump($user->createToken("development")->plainTextToken);
'
```

Envie o token no header:

```text
Authorization: Bearer SEU_TOKEN
```

## Endpoints

### Receber venda por webhook

```http
POST /api/webhooks/sales
```

Payload:

```json
{
  "external_id": "SALE-92831",
  "customer_id": 145,
  "amount": 350.00,
  "occurred_at": "2026-08-20T14:30:00"
}
```

Possíveis respostas:

- `202 Accepted`: venda nova aceita;
- `200 OK`: venda idêntica já recebida;
- `401 Unauthorized`: assinatura ausente ou inválida;
- `409 Conflict`: mesmo `external_id` com dados diferentes;
- `422 Unprocessable Entity`: payload inválido.

### Assinatura HMAC

O parceiro calcula um HMAC SHA-256 sobre o corpo JSON bruto usando o segredo compartilhado.

A assinatura hexadecimal deve ser enviada em:

```text
X-Webhook-Signature
```

Exemplo:

```bash
payload='{"external_id":"SALE-92831","customer_id":145,"amount":350.00,"occurred_at":"2026-08-20T14:30:00"}'

signature=$(printf '%s' "$payload" \
  | openssl dgst -sha256 \
      -hmac 'change-this-secret' \
  | awk '{print $2}')

curl -i \
  -X POST 'http://localhost:8080/api/webhooks/sales' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -H "X-Webhook-Signature: $signature" \
  --data "$payload"
```

A assinatura é comparada com `hash_equals()`, evitando comparação vulnerável a ataques de temporização.

### Consultar saldo

```http
GET /api/customers/{id}/points
Authorization: Bearer SEU_TOKEN
```

Exemplo:

```bash
curl \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer SEU_TOKEN' \
  http://localhost:8080/api/customers/145/points
```

Resposta:

```json
{
  "data": {
    "customer_id": 145,
    "name": "Test Customer",
    "points_balance": 35
  }
}
```

### Importar CSV

```http
POST /api/imports/sales
Authorization: Bearer SEU_TOKEN
Content-Type: multipart/form-data
```

Exemplo:

```bash
curl -X POST \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer SEU_TOKEN' \
  -F 'file=@sales.csv' \
  http://localhost:8080/api/imports/sales
```

O arquivo deve possuir o cabeçalho exato:

```csv
external_id,customer_id,amount,occurred_at
```

O endpoint retorna `202 Accepted`. O arquivo é processado pelo worker de forma assíncrona.

### Consultar importação

```http
GET /api/imports/sales/{id}
Authorization: Bearer SEU_TOKEN
```

Retorna o status e os contadores:

```json
{
  "data": {
    "id": 1,
    "filename": "sales.csv",
    "status": "completed",
    "total_rows": 3,
    "processed_rows": 1,
    "ignored_rows": 1,
    "error_rows": 1,
    "error_message": null
  }
}
```

### Consultar linhas da importação

```http
GET /api/imports/sales/{id}/rows
Authorization: Bearer SEU_TOKEN
```

Filtros:

```text
?status=processed
?status=ignored
?status=error
?per_page=50
```

Somente o usuário responsável pela importação pode consultar seus resultados.

## Decisões técnicas

### Regra centralizada de criação

Webhook e CSV são apenas entradas diferentes para a mesma entidade `Sale`.

Ambos utilizam:

```text
CreateSaleService
```

As regras de validação também são compartilhadas por meio de:

```text
SaleValidationRules
```

Isso evita diferenças de comportamento entre webhook e CSV.

### Valores monetários

O valor da venda é armazenado como `DECIMAL(15,2)`. Não é utilizado `FLOAT`, pois valores binários de ponto flutuante podem produzir imprecisões monetárias.

O cálculo utiliza BC Math:

```php
$points = (int) bcdiv($sale->amount, '10', 0);
```

A escala zero descarta a fração, conforme o requisito.

## Idempotência

A idempotência existe em mais de uma camada.

### Entrada da venda

`sales.external_id` possui constraint `UNIQUE`.

Dessa forma, webhook e CSV não conseguem persistir duas vendas com o mesmo identificador, inclusive sob requisições concorrentes.

Uma repetição com os mesmos dados é tratada como sucesso idempotente.

Se o mesmo `external_id` chegar com cliente, valor ou data diferentes, a API retorna conflito. Isso evita aceitar silenciosamente informações inconsistentes.

### Processamento da pontuação

`point_transactions.sale_id` também possui constraint `UNIQUE`.

Além disso, o Job bloqueia a venda com `lockForUpdate()` e verifica seu status antes do processamento.

Mesmo que o worker execute o mesmo Job mais de uma vez, somente uma transação de pontos será criada.

## Concorrência

O processamento utiliza transação de banco e bloqueios pessimistas:

1. bloqueia a venda;
2. verifica se já foi processada;
3. bloqueia o cliente;
4. cria o histórico de pontos;
5. incrementa o saldo;
6. marca a venda como processada.

Duas vendas simultâneas do mesmo cliente são serializadas pelo bloqueio da linha do cliente.

O incremento também é realizado diretamente no banco:

```php
$customer->increment('points_balance', $points);
```

Isso evita o problema de leitura, alteração em memória e sobrescrita de saldo.

A transação garante que histórico, saldo e status sejam confirmados ou revertidos juntos.

## Processamento assíncrono e falhas

Os Jobs utilizam Redis e possuem:

- número limitado de tentativas;
- backoff progressivo;
- timeout explícito;
- registro de falhas;
- status persistido no banco;
- mensagens de erro para investigação.

O Job de pontuação utiliza três tentativas com intervalos progressivos.

Se todas falharem, a venda é marcada como `failed` e a mensagem é armazenada em `processing_error`.

Vendas persistidas que não chegaram ao Redis podem ser recuperadas pelo comando:

```bash
php artisan sales:redispatch-pending
```

Essa recuperação continua segura porque o processamento é idempotente.

## Estratégia de importação CSV

O upload somente armazena o arquivo, cria o registro da importação e dispara um Job. A requisição HTTP não fica aguardando o processamento completo.

O arquivo é lido através de stream e `fgetcsv()`, uma linha por vez. Portanto, ele não é carregado integralmente em memória.

Para cada linha:

1. a quantidade de colunas é validada;
2. os dados são associados ao cabeçalho;
3. as regras compartilhadas são aplicadas;
4. a venda passa pelo mesmo serviço usado pelo webhook;
5. o resultado é registrado em `sale_import_rows`.

Uma linha inválida não interrompe as próximas.

Os resultados possíveis são:

- `processed`: venda criada;
- `ignored`: venda já existente;
- `error`: registro inválido ou conflitante.

A combinação de `sale_import_id` e `line_number` é única. Isso permite reiniciar o Job sem repetir linhas já auditadas.

## Logs e observabilidade

São registrados eventos como:

- assinatura de webhook inválida;
- payload inválido;
- venda criada;
- venda duplicada;
- tentativa duplicada de pontuação;
- pontuação processada;
- falha definitiva do Job;
- início, conclusão e rejeição de CSV;
- totais da importação.

Em uma implantação real, os logs seriam enviados para uma solução centralizada, como CloudWatch, Datadog, Grafana Loki ou Elastic Stack.

Também seriam monitorados:

- tamanho das filas;
- tempo de espera dos Jobs;
- taxa de falhas;
- quantidade de vendas pendentes;
- duração das importações;
- crescimento das tabelas de auditoria.

## Produção

Para produção, seriam adotados:

- `APP_ENV=production`;
- `APP_DEBUG=false`;
- segredos armazenados em secret manager;
- HTTPS obrigatório;
- banco e Redis gerenciados;
- múltiplos workers supervisionados;
- Laravel Horizon para monitoramento do Redis;
- backups e restauração testada;
- logs centralizados;
- métricas e alertas;
- deploy com migrations controladas;
- health checks;
- rate limiting;
- rotação do segredo HMAC;
- política de retenção para CSVs e registros de auditoria.

O Nginx e o PHP-FPM seriam executados em imagens próprias e imutáveis, sem bind mount do código-fonte.

## Aumento significativo de volume

Com crescimento do volume, os principais riscos seriam:

### Contenção no saldo do cliente

Clientes com muitas vendas simultâneas podem concentrar bloqueios.

Possíveis evoluções:

- particionar o processamento por cliente;
- garantir ordem por chave de cliente na fila;
- manter um ledger imutável e calcular projeções;
- processar incrementos em lotes quando permitido pelo negócio.

### Crescimento do histórico

`sales`, `point_transactions` e `sale_import_rows` crescerão continuamente.

Possíveis medidas:

- particionamento por data;
- arquivamento de registros antigos;
- índices revisados com dados reais;
- réplicas de leitura;
- política de retenção para auditoria.

### Arquivos CSV muito grandes

A leitura atual possui memória constante, mas um único Job ainda pode permanecer ativo por bastante tempo.

Em escala maior, o arquivo seria dividido em blocos, com:

- um Job coordenador;
- vários Jobs de lote;
- contador atômico de conclusão;
- limite de paralelismo;
- armazenamento em object storage;
- fila separada para importações;
- dead-letter queue.

### Banco como ponto de contenção

Poderiam ser adotados:

- connection pooling;
- réplicas de leitura;
- particionamento;
- otimização de índices;
- filas separadas por tipo de trabalho;
- testes de carga e observação de queries lentas.

## Melhorias com mais tempo

- testes reais de concorrência contra MySQL;
- API administrativa para repetir vendas com falha;
- endpoint de autenticação, caso faça parte do produto;
- versionamento e rotação de segredos HMAC;
- timestamp assinado para limitar replay de requisições antigas;
- identificação do parceiro e unicidade composta por parceiro;
- Laravel Horizon;
- object storage para arquivos;
- processamento de CSV dividido em Jobs menores;
- métricas e dashboards;
- testes de carga;
- documentação OpenAPI.

## Limitações assumidas

O `external_id` é considerado globalmente único porque o payload do exercício não contém `partner_id`.

Em um cenário com múltiplos parceiros e possibilidade de identificadores iguais, seria criada uma entidade `partners`, e a constraint passaria a ser composta:

```text
UNIQUE(partner_id, external_id)
```

A suíte automatizada utiliza SQLite em memória por velocidade e isolamento. As garantias específicas de `lockForUpdate()` dependem do MySQL e devem ser complementadas por testes de integração reais.

## Uso de Inteligência Artificial

Foi utilizada a ferramenta ChatGPT/Codex como apoio durante o exercício.

A ferramenta foi utilizada para:

- discutir alternativas de arquitetura;
- revisar estratégias de idempotência e concorrência;
- sugerir estruturas iniciais de código;
- auxiliar na elaboração de testes;
- revisar configuração Docker e documentação;
- investigar mensagens de erro durante o desenvolvimento.

Foram aceitas e adaptadas sugestões relacionadas a:

- HMAC SHA-256;
- constraints únicas;
- transações e bloqueios pessimistas;
- processamento por filas;
- leitura progressiva do CSV;
- auditoria de importações;
- testes automatizados.

As sugestões foram revisadas e ajustadas durante a execução. Por exemplo, uma tentativa inicial de recompilar extensões XML do PHP 8.5 foi descartada após identificar uma incompatibilidade desnecessária com a biblioteca Lexbor.

Também foram tomadas decisões próprias sobre nomenclatura, estrutura final, regras de conflito, escopo dos endpoints e equilíbrio entre robustez e tempo disponível.
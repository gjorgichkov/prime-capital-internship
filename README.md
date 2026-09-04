# Client accounts API

A small Laravel API that tracks client accounts. Each client has cash and securities, every movement
(deposit, withdrawal, buy, sell) is recorded, and the API reports how much cash and which instruments a
client currently holds.

Two rules are enforced strictly: cash can never go negative, and a client cannot sell units they do not
hold. A movement that would break either is rejected outright and the account is left exactly as it was.

Built with Laravel 13 on PHP 8.4 and MySQL 8.4, all inside Docker.

## Running it

The only things needed on the host are Docker and Bash:

```bash
./bin/setup.sh
```

That installs the dependencies, starts the containers, migrates and seeds. It is safe to run again. When
it finishes, the API is at `http://localhost:8000` (change `APP_PORT` in `.env` if that clashes).

The seed data includes the worked example from the task, so this should return `860.00` cash and 2 AAPL:

```bash
curl localhost:8000/api/clients/1 -H 'Accept: application/json'
```



## Endpoints


| Method | Path                                 | Purpose                   |
| ------ | ------------------------------------ | ------------------------- |
| `GET`  | `/api/clients`                       | List clients              |
| `POST` | `/api/clients`                       | Create a client           |
| `GET`  | `/api/clients/{client}`              | Current cash and holdings |
| `GET`  | `/api/clients/{client}/transactions` | Full movement history     |
| `POST` | `/api/clients/{client}/transactions` | Record a movement         |


All five, as commands you can paste. The `Accept` header is what makes errors come back as JSON instead
of HTML:

```bash
# Create a client
curl -X POST localhost:8000/api/clients \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"name":"Dimitar"}'

# List clients
curl localhost:8000/api/clients -H 'Accept: application/json'

# Current cash and holdings
curl localhost:8000/api/clients/1 -H 'Accept: application/json'

# Every movement, oldest first
curl localhost:8000/api/clients/1/transactions -H 'Accept: application/json'

# Record a deposit, or a withdrawal with "type": "withdrawal"
curl -X POST localhost:8000/api/clients/1/transactions \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"type":"deposit","amount":"1000.00"}'

# Record a buy, or a sell with "type": "sell". The cash amount is derived from
# quantity * price_per_unit rather than sent, so the two cannot disagree.
curl -X POST localhost:8000/api/clients/1/transactions \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"type":"buy","instrument":"AAPL","quantity":5,"price_per_unit":"100.00"}'
```

A movement carries no `client_id`: the client comes from the URL, so a payload cannot redirect a movement
to someone else's account.

`GET /api/clients/{client}` answers the question the task actually asks, how much cash and which
instruments:

```json
{
  "data": {
    "id": 1,
    "name": "Ana",
    "cash_balance": "860.00",
    "holdings": [{ "instrument": "AAPL", "quantity": 2 }]
  }
}
```



## Decisions worth knowing

**Nothing is stored as a running total.** Cash and holdings are aggregated from the movements every time
they are asked for. Movements are only ever appended, so there is no second copy of the truth that could
drift away from the ledger. An instrument sold down to nothing disappears from the holdings rather than
showing as zero.

**Money is stored as integer minor units, never a float.** Most two-decimal amounts have no exact binary
representation, so the obvious `(int) ($value * 100)` loses a cent surprisingly often. With integers the
arithmetic is exact.

**Money crosses the wire as a decimal string,** like `"1000.00"`. A JSON number is parsed as a float, so
sending `1000.55` would already have altered the value before it arrived. Whole numbers are exact, so
`1000` is accepted; a decimal float is rejected with an explanation.

**Both rules are read-then-write decisions,** so they are checked while holding a row lock on the client.
Without it, two simultaneous requests could each read a balance of 500 and each approve a 400 purchase.
The balance is read with `FOR UPDATE` too, because MySQL's default isolation level would otherwise serve
a plain read from a snapshot and could return stale data even while correctly holding the lock.

**Tickers are uppercased on the way in** and compared case sensitively in the database, so `aapl` and
`AAPL` are one holding rather than two.

**The database is the last line of defence.** Four `CHECK` constraints reject rows that contradict
themselves, so the table stays coherent even for something written outside the application. Cash going
negative is the one rule no `CHECK` can express, since it is an aggregate over many rows rather than a
property of one, which is exactly why the lock is needed.

## Errors

Malformed input and broken rules both return `422` in the same shape, so there is one error format to
handle. A broken rule adds a machine-readable `code`, which is either `insufficient_funds` or
`insufficient_holdings`; plain validation failures have no `code`.

```json
{
  "message": "Cannot withdraw 999.00: the available cash balance is 860.00.",
  "code": "insufficient_funds",
  "errors": { "amount": ["Cannot withdraw 999.00: the available cash balance is 860.00."] }
}
```



## Tests

```bash
./vendor/bin/sail artisan test
```

114 tests covering the four movement types, both rules and that a rejection leaves the account unchanged,
validation, and the `CHECK` constraints. `ConcurrentWriteTest` uses two real database connections to show
the lock is genuinely exclusive rather than assumed.

## Out of scope

Deliberately not built, per the task: profit and loss, an instruments catalogue, authentication, multiple
currencies, and editing or deleting movements.
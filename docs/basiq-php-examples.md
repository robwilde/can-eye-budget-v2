# Basiq PHP Examplesfropm the Basiq API documentation.

## Data

### Accounts


#### List All Accounts

```php
<?php
require_once('vendor/autoload.php');

$client = new \GuzzleHttp\Client();

$response = $client->request('GET', 'https://au-api.basiq.io/users/userId/accounts', [
  'headers' => [
    'accept' => 'application/json',
    'authorization' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJwYXJ0bmVyaWQiOiJkMTRjYzU5Zi0yM2UzLTQ4NjEtOGJiMi1mZWMwZDcxMWMxMjkiLCJhcHBsaWNhdGlvbmlkIjoiNjMxMjNmMWMtZjYxMy00ZjMyLWFiYzUtYzBhZDdhYTY2YmU1IiwiYXBwbGljYXRpb25fdHlwZSI6IlJlZ3VsYXIiLCJ1c2VyaWQiOiI2ZGQzMGNlNC1kNGJhLTExZWMtOWQ2NC0wMjQyYWMxMjAwMDIiLCJzY29wZSI6IkNMSUVOVF9BQ0NFU1MiLCJzYW5kYm94X2FjY291bnQiOnRydWUsImNvbm5lY3Rfc3RhdGVtZW50cyI6ZmFsc2UsImVucmljaCI6ImRpc2FibGVkIiwiZW5yaWNoX2FwaV9rZXkiOiJzSkVHRHdJNnhvOGw0b3hWYjBrQUgxNjI2b1FrYThpVDFYQTMxeExhIiwiZW5yaWNoX2VudGl0eSI6ZmFsc2UsImVucmljaF9sb2NhdGlvbiI6ZmFsc2UsImVucmljaF9jYXRlZ29yeSI6ZmFsc2UsImFmZm9yZGFiaWxpdHkiOiJzYW5kYm94IiwiaW5jb21lIjoic2FuZGJveCIsImV4cGVuc2VzIjoic2FuZGJveCIsImV4cCI6MTc3NDE5MzIzOCwiaWF0IjoxNzc0MTg5NjM4LCJ2ZXJzaW9uIjoiMy4wIiwiZGVuaWVkX3Blcm1pc3Npb25zIjpbXX0.l-c2ghnNUp6UtvO74f2nEyXx87ujZvXxgutO0Mj72mw',
  ],
]);

echo $response->getBody();
```

#### Retrieve an Account

```php
<?php
require_once('vendor/autoload.php');

$client = new \GuzzleHttp\Client();

$response = $client->request('GET', 'https://au-api.basiq.io/users/userId/accounts/accountId', [
  'headers' => [
    'accept' => 'application/json',
    'authorization' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJwYXJ0bmVyaWQiOiJkMTRjYzU5Zi0yM2UzLTQ4NjEtOGJiMi1mZWMwZDcxMWMxMjkiLCJhcHBsaWNhdGlvbmlkIjoiNjMxMjNmMWMtZjYxMy00ZjMyLWFiYzUtYzBhZDdhYTY2YmU1IiwiYXBwbGljYXRpb25fdHlwZSI6IlJlZ3VsYXIiLCJ1c2VyaWQiOiI2ZGQzMGNlNC1kNGJhLTExZWMtOWQ2NC0wMjQyYWMxMjAwMDIiLCJzY29wZSI6IkNMSUVOVF9BQ0NFU1MiLCJzYW5kYm94X2FjY291bnQiOnRydWUsImNvbm5lY3Rfc3RhdGVtZW50cyI6ZmFsc2UsImVucmljaCI6ImRpc2FibGVkIiwiZW5yaWNoX2FwaV9rZXkiOiJzSkVHRHdJNnhvOGw0b3hWYjBrQUgxNjI2b1FrYThpVDFYQTMxeExhIiwiZW5yaWNoX2VudGl0eSI6ZmFsc2UsImVucmljaF9sb2NhdGlvbiI6ZmFsc2UsImVucmljaF9jYXRlZ29yeSI6ZmFsc2UsImFmZm9yZGFiaWxpdHkiOiJzYW5kYm94IiwiaW5jb21lIjoic2FuZGJveCIsImV4cGVuc2VzIjoic2FuZGJveCIsImV4cCI6MTc3NDE5MzIzOCwiaWF0IjoxNzc0MTg5NjM4LCJ2ZXJzaW9uIjoiMy4wIiwiZGVuaWVkX3Blcm1pc3Npb25zIjpbXX0.l-c2ghnNUp6UtvO74f2nEyXx87ujZvXxgutO0Mj72mw',
  ],
]);

echo $response->getBody();
```

### Transactions

#### List All Transactions

```php
<?php
require_once('vendor/autoload.php');

$client = new \GuzzleHttp\Client();

$response = $client->request('GET', 'https://au-api.basiq.io/users/6dd30ce4-d4ba-11ec-9d64-0242ac120002/transactions?limit=500', [
  'headers' => [
    'accept' => 'application/json',
    'authorization' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJwYXJ0bmVyaWQiOiJkMTRjYzU5Zi0yM2UzLTQ4NjEtOGJiMi1mZWMwZDcxMWMxMjkiLCJhcHBsaWNhdGlvbmlkIjoiNjMxMjNmMWMtZjYxMy00ZjMyLWFiYzUtYzBhZDdhYTY2YmU1IiwiYXBwbGljYXRpb25fdHlwZSI6IlJlZ3VsYXIiLCJ1c2VyaWQiOiI2ZGQzMGNlNC1kNGJhLTExZWMtOWQ2NC0wMjQyYWMxMjAwMDIiLCJzY29wZSI6IkNMSUVOVF9BQ0NFU1MiLCJzYW5kYm94X2FjY291bnQiOnRydWUsImNvbm5lY3Rfc3RhdGVtZW50cyI6ZmFsc2UsImVucmljaCI6ImRpc2FibGVkIiwiZW5yaWNoX2FwaV9rZXkiOiJzSkVHRHdJNnhvOGw0b3hWYjBrQUgxNjI2b1FrYThpVDFYQTMxeExhIiwiZW5yaWNoX2VudGl0eSI6ZmFsc2UsImVucmljaF9sb2NhdGlvbiI6ZmFsc2UsImVucmljaF9jYXRlZ29yeSI6ZmFsc2UsImFmZm9yZGFiaWxpdHkiOiJzYW5kYm94IiwiaW5jb21lIjoic2FuZGJveCIsImV4cGVuc2VzIjoic2FuZGJveCIsImV4cCI6MTc3NDE5MzIzOCwiaWF0IjoxNzc0MTg5NjM4LCJ2ZXJzaW9uIjoiMy4wIiwiZGVuaWVkX3Blcm1pc3Npb25zIjpbXX0.l-c2ghnNUp6UtvO74f2nEyXx87ujZvXxgutO0Mj72mw',
  ],
]);

echo $response->getBody();
```

#### Retrieve a Transaction

```php
<?php
require_once('vendor/autoload.php');

$client = new \GuzzleHttp\Client();

$response = $client->request('GET', 'https://au-api.basiq.io/users/6dd30ce4-d4ba-11ec-9d64-0242ac120002/transactions/8892c200-ca4b-46c5-aa6f-77b354766001', [
  'headers' => [
    'accept' => 'application/json',
    'authorization' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJwYXJ0bmVyaWQiOiJkMTRjYzU5Zi0yM2UzLTQ4NjEtOGJiMi1mZWMwZDcxMWMxMjkiLCJhcHBsaWNhdGlvbmlkIjoiNjMxMjNmMWMtZjYxMy00ZjMyLWFiYzUtYzBhZDdhYTY2YmU1IiwiYXBwbGljYXRpb25fdHlwZSI6IlJlZ3VsYXIiLCJ1c2VyaWQiOiI2ZGQzMGNlNC1kNGJhLTExZWMtOWQ2NC0wMjQyYWMxMjAwMDIiLCJzY29wZSI6IkNMSUVOVF9BQ0NFU1MiLCJzYW5kYm94X2FjY291bnQiOnRydWUsImNvbm5lY3Rfc3RhdGVtZW50cyI6ZmFsc2UsImVucmljaCI6ImRpc2FibGVkIiwiZW5yaWNoX2FwaV9rZXkiOiJzSkVHRHdJNnhvOGw0b3hWYjBrQUgxNjI2b1FrYThpVDFYQTMxeExhIiwiZW5yaWNoX2VudGl0eSI6ZmFsc2UsImVucmljaF9sb2NhdGlvbiI6ZmFsc2UsImVucmljaF9jYXRlZ29yeSI6ZmFsc2UsImFmZm9yZGFiaWxpdHkiOiJzYW5kYm94IiwiaW5jb21lIjoic2FuZGJveCIsImV4cGVuc2VzIjoic2FuZGJveCIsImV4cCI6MTc3NDE5MzIzOCwiaWF0IjoxNzc0MTg5NjM4LCJ2ZXJzaW9uIjoiMy4wIiwiZGVuaWVkX3Blcm1pc3Npb25zIjpbXX0.l-c2ghnNUp6UtvO74f2nEyXx87ujZvXxgutO0Mj72mw',
  ],
]);

echo $response->getBody();
```
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use Tnapf\Router\Router;
use Tnapf\Router\Routing\RouteRunner;

function createDbConnection(): \PDO {
    $dsn = sprintf(
        '%s:host=%s;port=%s;dbname=%s',
        getenv('DB_CONNECTION') ?: 'mysql',
        getenv('DB_HOST') ?: '127.0.0.1',
        getenv('DB_PORT') ?: 3306,
        getenv('DB_NAME') ?: 'rinha',
    );

    if (getenv('DB_CONNECTION') === 'mysql') {
        $dsn .= sprintf(";charset=%s", getenv('DB_CHARSET') ?: 'utf8mb4');
    }

    $username = getenv('DB_USER') ?: 'rinha';
    $password = getenv('DB_PASSWORD') ?: 'rinha';

    $connection = new \PDO($dsn, $username, $password, [
        \PDO::ATTR_PERSISTENT => true
    ]);
    $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    return $connection;
}

$router = new Router();

$dbConnection = createDbConnection();

$router->get('/clientes/{id}/extrato', static function (
    ServerRequestInterface $request,
    ResponseInterface $response,
    RouteRunner $route,
) use ($dbConnection) {
    $customerId = $route->getParameter('id');

    $now = new \DateTime();

    $customerQuery = "
        SELECT *
        FROM clientes
        WHERE id = :customerId
    ";

    $customerStatement = $dbConnection->prepare($customerQuery);
    $customerStatement->bindParam(':customerId', $customerId);
    $customerStatement->execute();

    $customerResult = $customerStatement->fetch(\PDO::FETCH_ASSOC);
    if (! $customerResult) {
        return new Response(Response::STATUS_NOT_FOUND);
    }

    $customerLastTransactionsQuery = "
        SELECT valor, tipo, descricao, realizada_em
        FROM transacoes
        WHERE cliente_id = :customerId
        ORDER BY id DESC
        LIMIT 10
    ";

    $customerLastTransactionsStatement = $dbConnection->prepare($customerLastTransactionsQuery);
    $customerLastTransactionsStatement->bindParam(':customerId', $customerId);
    $customerLastTransactionsStatement->execute();

    $customerLastTransactionsResult = $customerLastTransactionsStatement->fetchAll(\PDO::FETCH_ASSOC);

    return Response::json([
        'saldo' => [
            'total' => intval($customerResult['saldo']),
            'data_extrato' => $now->format(\DateTime::ATOM),
            'limite' => intval($customerResult['limite']),
        ],
        'ultimas_transacoes' => $customerLastTransactionsResult
            ? array_map(function ($row) {
                return [
                    'valor' => intval($row['valor']),
                    'tipo' => $row['tipo'],
                    'descricao' => $row['descricao'],
                    'realizada_em' => (new \DateTime($row['realizada_em']))->format(\DateTime::ATOM),
                ];
            }, $customerLastTransactionsResult)
            : []
    ]);
});

$router->post('/clientes/{id}/transacoes', static function (
    ServerRequestInterface $request,
    ResponseInterface $response,
    RouteRunner $route,
) use ($dbConnection) {
    $requestData = json_decode((string) $request->getBody(), true);
    if (!$requestData) {
        return new Response(Response::STATUS_BAD_REQUEST);
    }

    $amount = $requestData['valor'];
    $type = $requestData['tipo'];
    $description = $requestData['descricao'];

    if (empty($amount) || empty($type) || empty($description)) {
        return new Response(Response::STATUS_UNPROCESSABLE_ENTITY);
    }

    if ($amount <= 0 || ! is_int($amount)) {
        return new Response(Response::STATUS_UNPROCESSABLE_ENTITY);
    }

    if (!in_array($type, ['c', 'd'])) {
        return new Response(Response::STATUS_UNPROCESSABLE_ENTITY);
    }

    $descriptionLength = strlen($description);
    if ($descriptionLength < 0 || $descriptionLength > 10) {
        return new Response(Response::STATUS_UNPROCESSABLE_ENTITY);
    }

    $customerId = $route->getParameter('id');

    $customerQuery = "
        SELECT *
        FROM clientes
        WHERE id = :customerId
    ";

    $customerStatement = $dbConnection->prepare($customerQuery);
    $customerStatement->bindParam(':customerId', $customerId);
    $customerStatement->execute();

    $customerResult = $customerStatement->fetch(\PDO::FETCH_ASSOC);
    if (! $customerResult) {
        return new Response(Response::STATUS_NOT_FOUND);
    }

    $createTransactionQuery = "
        CALL create_transaction(:customerId, :amount, :type, :description)
    ";

    $createTransactionStatement = $dbConnection->prepare($createTransactionQuery);
    $createTransactionStatement->bindParam(':customerId', $customerId, \PDO::PARAM_INT);
    $createTransactionStatement->bindParam(':amount', $amount, \PDO::PARAM_INT);
    $createTransactionStatement->bindParam(':type', $type, \PDO::PARAM_STR);
    $createTransactionStatement->bindParam(':description', $description, \PDO::PARAM_STR);

    try {
        $createTransactionStatement->execute();
    } catch (\PDOException $e) {
        return new Response(Response::STATUS_UNPROCESSABLE_ENTITY);
    }

    $customerQuery = "
        SELECT *
        FROM clientes
        WHERE id = :customerId
    ";

    $customerStatement = $dbConnection->prepare($customerQuery);
    $customerStatement->bindParam(':customerId', $customerId);
    $customerStatement->execute();

    $customerResult = $customerStatement->fetch(\PDO::FETCH_ASSOC);

    return Response::json([
        'limite' => intval($customerResult['limite']),
        'saldo' => intval($customerResult['saldo'])
    ]);
});

$router->catch(
    \Throwable::class,
    static function (
        ServerRequestInterface $request,
        ResponseInterface $response,
        RouteRunner $route,
    ) {
        $exception = $route->exception;
        $exceptionString = $exception->getMessage() . "\n" . $exception->getTraceAsString();

        $response->getBody()->write($exceptionString);

        return $response
            ->withStatus(Response::STATUS_INTERNAL_SERVER_ERROR)
            ->withHeader("Content-Type", "text/plain");
    }
);

$http = new HttpServer(static function (ServerRequestInterface $request) use ($router) {
    return $router->run($request);
});

$http->listen(
    new SocketServer('0.0.0.0:8080'),
);

echo "Server running at 8080" . PHP_EOL;

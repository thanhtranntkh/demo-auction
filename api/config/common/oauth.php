<?php

declare(strict_types=1);

use App\OAuth\Entity\AccessTokenRepository;
use App\OAuth\Entity\AuthCode;
use App\OAuth\Entity\AuthCodeRepository;
use App\OAuth\Entity\Client;
use App\OAuth\Entity\ClientRepository;
use App\OAuth\Entity\RefreshToken;
use App\OAuth\Entity\RefreshTokenRepository;
use App\OAuth\Entity\Scope;
use App\OAuth\Entity\ScopeRepository;
use App\OAuth\Entity\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use Psr\Container\ContainerInterface;
use function App\env;

return [
    AuthorizationServer::class => static function (ContainerInterface $container): AuthorizationServer {
        /**
         * @psalm-suppress MixedArrayAccess
         * @var array{
         *    private_key_path:string,
         *    encryption_key:string,
         *    auth_code_interval:string,
         *    access_token_interval:string,
         *    refresh_token_interval:string,
         * } $config
         */
        $config = $container->get('config')['oauth'];

        $clientRepository = $container->get(ClientRepositoryInterface::class);
        $scopeRepository = $container->get(ScopeRepositoryInterface::class);
        $accessTokenRepository = $container->get(AccessTokenRepositoryInterface::class);
        $authCodeRepository = $container->get(AuthCodeRepositoryInterface::class);
        $refreshTokenRepository = $container->get(RefreshTokenRepositoryInterface::class);

        $server = new AuthorizationServer(
            $clientRepository,
            $accessTokenRepository,
            $scopeRepository,
            new CryptKey($config['private_key_path'], null, false),
            $config['encryption_key']
        );

        $grant = new AuthCodeGrant(
            $authCodeRepository,
            $refreshTokenRepository,
            new DateInterval($config['auth_code_interval'])
        );
        $grant->setRefreshTokenTTL(new DateInterval($config['refresh_token_interval']));
        $server->enableGrantType($grant, new DateInterval($config['access_token_interval']));

        $grant = new RefreshTokenGrant($refreshTokenRepository);
        $grant->setRefreshTokenTTL(new DateInterval($config['refresh_token_interval']));
        $server->enableGrantType($grant, new DateInterval($config['access_token_interval']));

        return $server;
    },
    ScopeRepositoryInterface::class => static function (ContainerInterface $container): ScopeRepository {
        /**
         * @psalm-suppress MixedArrayAccess
         * @psalm-var array{scopes: string[]} $config
         */
        $config = $container->get('config')['oauth'];

        return new ScopeRepository(
            array_map(static fn (string $item): Scope => new Scope($item), $config['scopes'])
        );
    },
    ClientRepositoryInterface::class => static function (ContainerInterface $container): ClientRepository {
        /**
         * @psalm-suppress MixedArrayAccess
         * @psalm-var array{
         *     clients: array<array-key, array{
         *         name: string,
         *         client_id: string,
         *         redirect_uri: string
         *     }>
         * } $config
         */
        $config = $container->get('config')['oauth'];

        return new ClientRepository(
            array_map(static function (array $item): Client {
                return new Client(
                    $item['client_id'],
                    $item['name'],
                    $item['redirect_uri']
                );
            }, $config['clients'])
        );
    },
    UserRepositoryInterface::class => DI\get(UserRepository::class),
    AccessTokenRepositoryInterface::class => DI\get(AccessTokenRepository::class),
    AuthCodeRepositoryInterface::class => static function (ContainerInterface $container): AuthCodeRepository {
        $em = $container->get(EntityManagerInterface::class);
        $repo = $em->getRepository(AuthCode::class);
        return new AuthCodeRepository($em, $repo);
    },
    RefreshTokenRepositoryInterface::class => static function (ContainerInterface $container): RefreshTokenRepository {
        $em = $container->get(EntityManagerInterface::class);
        $repo = $em->getRepository(RefreshToken::class);
        return new RefreshTokenRepository($em, $repo);
    },

    'config' => [
        'oauth' => [
            'scopes' => [
                'common',
            ],
            'clients' => [
                [
                    'name' => 'Auction',
                    'client_id' => 'frontend',
                    'redirect_uri' => env('FRONTEND_URL') . '/oauth',
                ],
            ],
            'encryption_key' => env('JWT_ENCRYPTION_KEY'),
            'public_key_path' => env('JWT_PUBLIC_KEY_PATH'),
            'private_key_path' => env('JWT_PRIVATE_KEY_PATH'),
            'auth_code_interval' => 'PT1M',
            'access_token_interval' => 'PT10M',
            'refresh_token_interval' => 'P7D',
        ],
    ],
];

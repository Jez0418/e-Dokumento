<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoutesTest extends TestCase
{
    private static function routes(): array
    {
        return require BASE_PATH . '/config/routes.php';
    }

    private static function access(string $path): string|array
    {
        return self::routes()[$path][1];
    }

    public function test_every_route_file_exists(): void
    {
        foreach (self::routes() as $path => [$file]) {
            $this->assertFileExists(BASE_PATH . '/' . $file, "Route {$path}");
        }
    }

    public function test_role_restricted_routes(): void
    {
        $expected = [
            '/users'           => ['admin'],
            '/settings'        => ['admin'],
            '/officials'       => ['admin'],
            '/document-types'  => ['admin'],
            '/requirements'    => ['admin'],
            '/reference'       => ['admin'],
            '/audit'           => ['admin', 'captain'],
            '/verifications'   => ['secretary'],
            '/residents/form'  => ['secretary'],
            '/requests/new'    => ['resident', 'secretary'],
            '/residents'       => ['admin', 'captain', 'secretary'],
            '/payments'        => ['admin', 'captain', 'secretary', 'treasurer'],
            '/reports'         => ['admin', 'captain', 'secretary', 'treasurer'],
            '/login'           => 'guest',
            '/register'        => 'guest',
            '/forgot-password' => 'guest',
            '/verify'          => 'public',
        ];
        foreach ($expected as $path => $access) {
            $this->assertSame($access, self::access($path), "Access for {$path}");
        }
    }

    public function test_secretary_cannot_reach_users(): void
    {
        $this->assertNotContains('secretary', self::access('/users'));
    }

    public function test_resident_cannot_reach_residents(): void
    {
        $this->assertNotContains('resident', self::access('/residents'));
    }

    public static function roles(): array
    {
        return [['admin'], ['captain'], ['secretary'], ['treasurer'], ['resident']];
    }

    #[DataProvider('roles')]
    public function test_nav_links_are_reachable_by_their_role(string $role): void
    {
        $routes = self::routes();
        foreach (nav_for_role($role) as [, $entries]) {
            foreach ($entries as [, $href]) {
                $path = strtok($href, '?');
                $this->assertArrayHasKey($path, $routes, "Nav link {$href} for {$role}");
                $access = $routes[$path][1];
                $this->assertTrue(
                    $access === 'auth' || $access === 'public' || (is_array($access) && in_array($role, $access, true)),
                    "Nav link {$href} is not reachable by {$role}"
                );
            }
        }
    }
}

<?php

namespace JMac\Testing\Traits;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Assert as PHPUnitAssert;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

trait AdditionalAssertions
{
    public function assertActionUsesFormRequest(string $controller, string $method, string $form_request): void
    {
        PHPUnitAssert::assertTrue(is_subclass_of($form_request, 'Illuminate\\Foundation\\Http\\FormRequest'), $form_request.' is not a type of Form Request');

        try {
            $reflector = new \ReflectionClass($controller);
            $action = $reflector->getMethod($method);
        } catch (\ReflectionException $exception) {
            PHPUnitAssert::fail('Controller action could not be found: '.$controller.'@'.$method);
        }

        PHPUnitAssert::assertTrue($action->isPublic(), 'Action "'.$method.'" is not public, controller actions must be public.');

        $actual = collect($action->getParameters())->contains(function ($parameter) use ($form_request) {
            return $parameter->getType() instanceof \ReflectionNamedType && $parameter->getType()->getName() === $form_request;
        });

        PHPUnitAssert::assertTrue($actual, 'Action "'.$method.'" does not have validation using the "'.$form_request.'" Form Request.');
    }

    public function assertActionUsesMiddleware($controller, $method, $middleware = null): void
    {
        $router = resolve(\Illuminate\Routing\Router::class);

        if (is_null($middleware)) {
            $middleware = $method;
            $method = '__invoke';
        }

        if ($method === '__invoke') {
            $route = $router->getRoutes()->getByAction($controller);

            PHPUnitAssert::assertNotNull($route, 'Unable to find route for invokable controller ('.$controller.')');
        } else {
            $route = $router->getRoutes()->getByAction($controller.'@'.$method);

            PHPUnitAssert::assertNotNull($route, 'Unable to find route for controller action ('.$controller.'@'.$method.')');
        }

        $excludedMiddleware = $route->action['excluded_middleware'] ?? [];
        $usedMiddlewares = array_diff($route->gatherMiddleware(), $excludedMiddleware);

        if (is_array($middleware)) {
            PHPUnitAssert::assertSame([], array_diff($middleware, $usedMiddlewares), 'Controller action does not use middleware ('.implode(', ', $middleware).')');
        } else {
            PHPUnitAssert::assertTrue(in_array($middleware, $usedMiddlewares), 'Controller action does not use middleware ('.$middleware.')');
        }
    }

    public static function assertArrayStructure(array $structure, array $actual)
    {
        foreach ($structure as $key => $type) {
            if (is_array($type) && $key === '*') {
                PHPUnitAssert::assertIsArray($actual);

                foreach ($actual as $data) {
                    static::assertArrayStructure($structure['*'], $data);
                }
            } elseif (is_array($type) && array_key_exists($key, $structure)) {
                if (is_array($structure[$key])) {
                    static::assertArrayStructure($structure[$key], $actual[$key]);
                }
            } else {
                switch ($type) {
                    case 'string':
                        PHPUnitAssert::assertIsString($actual[$key]);
                        break;
                    case 'integer':
                        PHPUnitAssert::assertIsInt($actual[$key]);
                        break;
                    case 'number':
                        PHPUnitAssert::assertIsNumeric($actual[$key]);
                        break;
                    case 'boolean':
                        PHPUnitAssert::assertIsBool($actual[$key]);
                        break;
                    case 'array':
                        PHPUnitAssert::assertIsArray($actual[$key]);
                        break;
                    default:
                        PHPUnitAssert::fail('unexpected type: '.$type);
                }
            }
        }
    }

    public function assertExactValidationRules(array $expected, array $actual): void
    {
        PHPUnitAssert::assertEquals($this->normalizeRules($expected), $this->normalizeRules($actual));
    }

    public function assertMiddlewareGroupUsesMiddleware(string $middlewareGroup, array $middlewares): void
    {
        $router = resolve(\Illuminate\Routing\Router::class);

        $kernel = new \App\Http\Kernel(app(), $router);

        $middlewareGroups = $kernel->getMiddlewareGroups();

        $missingMiddlware = array_diff($middlewares, $middlewareGroups[$middlewareGroup]);

        PHPUnitAssert::assertTrue(count($missingMiddlware) === 0, "Middleware Group `$middlewareGroup` does not use expected `".implode(', ', $missingMiddlware).'` middleware(s)');
    }

    public function assertNow(CarbonInterface $datetime, ?CarbonInterface $now = null): void
    {
        $now ??= Carbon::now();

        PHPUnitAssert::assertTrue($datetime->equalTo($now), 'Failed asserting the date time ['.$datetime->toDateTimeString('microsecond').'] is equal to now ['.$now->toDateTimeString('microsecond').']');
    }

    public function assertRouteUsesFormRequest(string $routeName, string $formRequest): void
    {
        $controllerAction = collect(Route::getRoutes())->filter(function (\Illuminate\Routing\Route $route) use ($routeName) {
            return $route->getName() == $routeName;
        })->pluck('action.controller');

        PHPUnitAssert::assertNotEmpty($controllerAction, 'Route "'.$routeName.'" is not defined.');
        PHPUnitAssert::assertCount(1, $controllerAction, 'Route "'.$routeName.'" is defined multiple times, route names should be unique.');

        $controller = $controllerAction->first();
        $method = '__invoke';
        if (strstr($controllerAction->first(), '@')) {
            [$controller, $method] = explode('@', $controllerAction->first());
        }

        $this->assertActionUsesFormRequest($controller, $method, $formRequest);
    }

    public function assertRouteUsesMiddleware(string $routeName, array $middlewares, bool $exact = false): void
    {
        $router = resolve(\Illuminate\Routing\Router::class);

        $route = $router->getRoutes()->getByName($routeName);

        PHPUnitAssert::assertNotNull($route, "Unable to find route for name `$routeName`");

        $excludedMiddleware = $route->action['excluded_middleware'] ?? [];
        $usedMiddlewares = array_diff($route->gatherMiddleware(), $excludedMiddleware);

        $unusedMiddlewares = array_diff($middlewares, $usedMiddlewares);

        PHPUnitAssert::assertTrue(count($unusedMiddlewares) === 0, "Route `$routeName` does not use expected `".implode(', ', $unusedMiddlewares).'` middleware(s)');

        if ($exact) {
            $extraMiddlewares = array_diff($usedMiddlewares, $middlewares);

            $messages = [];

            if ($extraMiddlewares) {
                $messages[] = 'uses unexpected `'.implode(', ', $extraMiddlewares).'` middlware(s)';
            }

            if ($unusedMiddlewares) {
                $messages[] = "doesn't use expected `".implode(', ', $unusedMiddlewares).'` middlware(s)';
            }

            $messages = implode(' and ', $messages);

            PHPUnitAssert::assertTrue(count($unusedMiddlewares) + count($extraMiddlewares) === 0, "Route `$routeName` ".$messages);
        }
    }

    public function assertValidationRuleContains($rule, string $class): void
    {
        if (is_object($rule)) {
            PHPUnitAssert::assertInstanceOf($rule, $class);

            return;
        }

        $matches = array_filter($this->expandRules($rule), function ($rule) use ($class) {
            return $rule instanceof $class;
        });

        if (empty($matches)) {
            PHPUnitAssert::fail('Failed asserting rule contains '.$class);
        }
    }

    public function assertValidationRules(array $expected, array $actual): void
    {
        \Illuminate\Testing\Assert::assertArraySubset($this->normalizeRules($expected), $this->normalizeRules($actual));
    }

    public function createFormRequest(string $form_request, array $data = [])
    {
        return $form_request::createFromBase(SymfonyRequest::create('', 'POST', $data));
    }

    public function freezeNow($subseconds = false): CarbonImmutable
    {
        $now = Carbon::now();

        if (! $subseconds) {
            $now = $now->startOfSecond();
        }

        $now = $now->toImmutable();

        Carbon::setTestNow($now);

        return $now;
    }

    private function expandRules($rule)
    {
        return is_string($rule) ? explode('|', $rule) : $rule;
    }

    private function normalizeRules(array $rules)
    {
        return array_map([$this, 'expandRules'], $rules);
    }
}

<?php

declare(strict_types=1);

namespace Dot\AnnotatedServices\Factory;

use ArrayAccess;
use Dot\AnnotatedServices\Annotation\Inject;
use Dot\AnnotatedServices\Exception\InvalidArgumentException;
use Dot\AnnotatedServices\Exception\RuntimeException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

use function array_shift;
use function class_exists;
use function count;
use function explode;
use function is_array;
use function sprintf;

class AnnotatedServiceFactory extends AbstractAnnotatedFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function __invoke(ContainerInterface $container, string $requestedName): mixed
    {
        return $this->createObject($container, $requestedName);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function createObject(ContainerInterface $container, string $requestedName): mixed
    {
        if (! class_exists($requestedName)) {
            throw RuntimeException::classNotFound($requestedName);
        }

        $service = null;

        $annotationReader = $this->createAnnotationReader($container);
        $refClass         = $this->getReflectionClass($requestedName);
        $constructor      = $refClass->getConstructor();

        if ($constructor === null) {
            $service = new $requestedName();
        } else {
            $inject = $annotationReader->getMethodAnnotation($constructor, Inject::class);
            if ($inject === null && $constructor->getNumberOfRequiredParameters() > 0) {
                throw RuntimeException::annotationNotFound(
                    Inject::class,
                    $requestedName,
                    static::class
                );
            }

            $services = [];
            if ($inject) {
                $services = $this->getServicesToInject($container, $inject);
            }

            $service = new $requestedName(...$services);
        }

        $methods = $refClass->getMethods(ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            $inject = $annotationReader->getMethodAnnotation($method, Inject::class);
            if ($inject) {
                $services = $this->getServicesToInject($container, $inject);
                $method->invoke($service, ...$services);
            }
        }

        return $service;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected function getServicesToInject(ContainerInterface $container, Inject $inject): array
    {
        $services = [];
        foreach ($inject->getServices() as $serviceKey) {
            $parts = explode('.', $serviceKey);
            // Even when dots are found, try to find a service with the full name
            // If it is not found, then assume dots are used to get part of an array service
            if (count($parts) > 1 && ! $container->has($serviceKey)) {
                $serviceKey = array_shift($parts);
            } else {
                $parts = [];
            }

            if ($container->has($serviceKey)) {
                $service = $container->get($serviceKey);
            } elseif (class_exists($serviceKey)) {
                $service = new $serviceKey();
            } else {
                throw RuntimeException::classNotFound($serviceKey);
            }

            $services[] = empty($parts) ? $service : $this->readKeysFromArray($parts, $service);
        }

        return $services;
    }

    protected function readKeysFromArray(array $keys, mixed $array): mixed
    {
        $key = array_shift($keys);
        // When one of the provided keys is not found, throw an exception
        if (! isset($array[$key])) {
            throw new InvalidArgumentException(sprintf(
                'The key "%s" provided in the dotted notation could not be found in the array service',
                $key
            ));
        }
        $value = $array[$key];
        if (! empty($keys) && (is_array($value) || $value instanceof ArrayAccess)) {
            $value = $this->readKeysFromArray($keys, $value);
        }
        return $value;
    }

    /**
     * @throws ReflectionException
     */
    protected function getReflectionClass(string $requestedName): ReflectionClass
    {
        return new ReflectionClass($requestedName);
    }
}

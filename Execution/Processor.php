<?php
/**
 * Date: 30.11.15
 *
 * @author Portey Vasil <portey@gmail.com>
 */

namespace Youshido\GraphQLBundle\Execution;


use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Youshido\GraphQL\Execution\Processor as BaseProcessor;
use Youshido\GraphQL\Execution\ResolveInfo;
use Youshido\GraphQL\Field\AbstractField;
use Youshido\GraphQL\Field\Field;
use Youshido\GraphQL\Parser\Ast\Query;
use Youshido\GraphQL\Schema\AbstractSchema;
use Youshido\GraphQL\Type\TypeService;
use Youshido\GraphQL\Validator\ConfigValidator\ConfigValidator;
use Youshido\GraphQL\Validator\Exception\ResolveException;
use Youshido\GraphQLBundle\Config\Rule\TypeValidationRule;

class Processor extends BaseProcessor implements ContainerAwareInterface
{

    use ContainerAwareTrait;

    /** @var  LoggerInterface */
    protected $logger;

    /**
     * @inheritdoc
     */
    public function __construct(AbstractSchema $schema)
    {
        $validator = ConfigValidator::getInstance();
        $validator->addRule('type', new TypeValidationRule($validator));

        parent::__construct($schema);
    }


    /**
     * @inheritdoc
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function processPayload($queryString, $variables = [])
    {
        if ($this->logger) {
            $this->logger->debug(sprintf('GraphQL query: %s', $queryString), (array)$variables);
        }

        parent::processPayload($queryString, $variables);
    }

    /**
     * @inheritdoc
     */
    protected function resolveFieldValue(AbstractField $field, $contextValue, Query $query)
    {
        $resolveInfo = new ResolveInfo($field, $query->getFields(), $field->getType(), $this->executionContext);
        $args        = $this->parseArgumentsValues($field, $query);

        if ($field instanceof Field) {
            if ($resolveFunc = $field->getConfig()->getResolveFunction()) {
                if (is_array($resolveFunc) && count($resolveFunc) == 2 && strpos($resolveFunc[0], '@') === 0) {
                    $service = substr($resolveFunc[0], 1);
                    $method  = $resolveFunc[1];

                    if (!$this->container->has($service)) {
                        throw new ResolveException(sprintf('Resolve service "%s" not found for field "%s"', $service, $field->getName()));
                    }

                    $serviceInstance = $this->container->get($service);

                    if (!method_exists($serviceInstance, $method)) {
                        throw new ResolveException(sprintf('Resolve method "%s" not found in "%s" service for field "%s"', $method, $service, $field->getName()));
                    }

                    return $serviceInstance->$method($contextValue, $args, $resolveInfo);
                }

                return $resolveFunc($contextValue, $args, $resolveInfo);
            } elseif ($propertyValue = TypeService::getPropertyValue($contextValue, $field->getName())) {
                return $propertyValue;
            }
        } else { //instance of AbstractField
            if (in_array('Symfony\Component\DependencyInjection\ContainerAwareInterface', class_implements($field))) {
                $field->setContainer($this->container);
            }

            return $field->resolve($contextValue, $args, $resolveInfo);
        }

        return null;
    }

    public function setLogger($loggerAlias)
    {
        $this->logger = $loggerAlias ? $this->container->get($loggerAlias) : null;
    }
}
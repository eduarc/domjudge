<?php declare(strict_types=1);

namespace DOMJudgeBundle\Serializer;

use DOMJudgeBundle\Entity\ExternalRelationshipEntityInterface;
use DOMJudgeBundle\Service\EventLogService;
use JMS\Serializer\EventDispatcher\EventSubscriberInterface;
use JMS\Serializer\EventDispatcher\ObjectEvent;
use JMS\Serializer\JsonSerializationVisitor;

/**
 * Class SetExternalIdVisitor
 * @package DOMJudgeBundle\Serializer
 */
class SetExternalIdVisitor implements EventSubscriberInterface
{
    /**
     * @var EventLogService
     */
    protected $eventLogService;

    /**
     * ContestVisitor constructor.
     * @param EventLogService $eventLogService
     */
    public function __construct(EventLogService $eventLogService)
    {
        $this->eventLogService = $eventLogService;
    }

    /**
     * @inheritdoc
     */
    public static function getSubscribedEvents()
    {
        return [
            [
                'event' => 'serializer.post_serialize',
                'format' => 'json',
                'method' => 'onPostSerialize'
            ],
        ];
    }

    /**
     * @param ObjectEvent $event
     * @throws \Exception
     */
    public function onPostSerialize(ObjectEvent $event)
    {
        /** @var JsonSerializationVisitor $visitor */
        $visitor = $event->getVisitor();
        $object  = $event->getObject();

        try {
            if ($externalIdField = $this->eventLogService->externalIdFieldForEntity(get_class($object))) {
                $method = sprintf('get%s', ucfirst($externalIdField));
                if (method_exists($object, $method)) {
                    $visitor->setData('id', $object->{$method}());
                }
            }
        } catch (\BadMethodCallException $e) {
            // Ignore these exceptions, as this means this is not an entity or it is not configured
        }

        if ($object instanceof ExternalRelationshipEntityInterface) {
            foreach ($object->getExternalRelationships() as $field => $entity) {
                try {
                    if ($entity && $externalIdField = $this->eventLogService->externalIdFieldForEntity(get_class($entity))) {
                        $method = sprintf('get%s', ucfirst($externalIdField));
                        if (method_exists($entity, $method)) {
                            $visitor->setData($field, $entity->{$method}());
                        }
                    }
                } catch (\BadMethodCallException $e) {
                    // Ignore these exceptions, as this means this is not an entity or it is not configured
                }
            }
        }
    }
}

<?php declare(strict_types=1);

namespace DOMJudgeBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class Identifier extends Constraint
{
    public $message = 'Only alphanumeric characters and _- are allowed';
}

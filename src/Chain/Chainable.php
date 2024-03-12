<?php
namespace Gt\Promise\Chain;

use Closure;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionUnionType;
use Throwable;
use TypeError;

abstract class Chainable {
	/** @var callable|null */
	private $onResolved;
	/** @var callable|null */
	private $onRejected;

	public function __construct(
		?callable $onResolved,
		?callable $onRejected
	) {
		$this->onResolved = $onResolved;
		$this->onRejected = $onRejected;
	}

	public function callOnResolved(mixed $value):mixed {
		if(is_null($this->onResolved)) {
			return null;
		}

		return call_user_func($this->onResolved, $value);
	}

	/**
	 * @return mixed|Throwable|null New resolved value
	 * @noinspection PhpMissingReturnTypeInspection
	 */
	public function callOnRejected(Throwable $reason) {
		if(is_null($this->onRejected)) {
			return $reason;
		}

		return call_user_func($this->onRejected, $reason);
	}

	public function checkResolutionCallbackType(mixed $resolvedValue):void {
		if(isset($this->onResolved)) {
			$this->checkType($resolvedValue, $this->onResolved);
		}
	}

	public function checkRejectionCallbackType(Throwable $rejection):void {
		if(isset($this->onRejected)) {
			$this->checkType($rejection, $this->onRejected);
		}
	}

	/**
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	// phpcs:ignore
	private function checkType(mixed $value, callable $callable):void {
		if(!$callable instanceof Closure) {
			return;
		}

		$refFunction = new ReflectionFunction($callable);
		$refParameterList = $refFunction->getParameters();
		if(!isset($refParameterList[0])) {
			return;
		}
		$refParameter = $refParameterList[0];
		$nullable = $refParameter->allowsNull();

		if(is_null($value)) {
			if(!$nullable) {
				throw new ChainFunctionTypeError("Then function's parameter is not nullable");
			}
		}

		$allowedTypes = [];
		$refType = $refParameter->getType();

		if($refType instanceof ReflectionUnionType || $refType instanceof ReflectionIntersectionType) {
			/** @var ReflectionNamedType $refSubType */
			foreach($refType->getTypes() as $refSubType) {
				array_push($allowedTypes, $refSubType->getName());
			}
		}
		else {
			/** @var ?ReflectionNamedType $refType */
			array_push($allowedTypes, $refType?->getName());
		}

		$valueType = is_object($value)
			? get_class($value)
			: gettype($value);
		foreach($allowedTypes as $allowedType) {
			$allowedType = match($allowedType) {
				"int" => "integer",
				"float" => "double",
				default => $allowedType,
			};
			if(is_null($allowedType) || $allowedType === "mixed") {
// A typeless property is defined - allow anything!
				return;
			}
			if($allowedType === $valueType) {
				return;
			}

			if(is_a($valueType, $allowedType, true)) {
				return;
			}

			if($allowedType === "string") {
				if($valueType === "double" || $valueType === "integer") {
					return;
				}
			}
			if($allowedType === "double") {
				if(is_numeric($value)) {
					return;
				}
			}
		}

		throw new ChainFunctionTypeError("Value $value is not compatible with chainable parameter");
	}
}

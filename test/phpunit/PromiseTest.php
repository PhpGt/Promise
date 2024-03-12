<?php
namespace Gt\Promise\Test;

use ArithmeticError;
use DateTime;
use Exception;
use Gt\Promise\Deferred;
use Gt\Promise\Promise;
use Gt\Promise\PromiseException;
use Gt\Promise\PromiseResolvedWithAnotherPromiseException;
use Gt\Promise\PromiseState;
use Gt\Promise\PromiseWaitTaskNotSetException;
use Gt\Promise\Test\Helper\CustomPromise;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RangeException;
use RuntimeException;
use stdClass;
use Throwable;
use ValueError;

class PromiseTest extends TestCase {
	public function testOnFulfilledResolvesCorrectValue() {
		$value = "Example resolution value";
		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();
		$sut->then(
			self::mockCallable(1, $value)
		);

		$promiseContainer->resolve($value);
	}

	public function testOnFulfilledShouldForwardValue() {
		$value = "Example resolution value";
		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();

		$sut->then(function(string $message) {
			return $message;
		})->then(
			self::mockCallable(1, $value)
		);

		$promiseContainer->resolve($value);
	}


	public function testPromiseRejectsIfResolvedWithItself() {
		$actualMessage = null;

		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();
		$onResolvedCallCount = 0;
		$sut->then(function($value) use (&$onResolvedCallCount) {
			$onResolvedCallCount++;
		})
			->catch(function(PromiseException $reason) use (&$actualMessage) {
				$actualMessage = $reason->getMessage();
			});

		$promiseContainer->resolve($sut);
		self::assertEquals(0, $onResolvedCallCount);
		self::assertSame("A Promise must not be resolved with another Promise.", $actualMessage);
	}

	public function testRejectWithException() {
		$exception = new Exception("Example");
		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();

		$fulfilledCallCount = 0;

		$sut->then(function() use (&$fulfilledCallCount) {
			$fulfilledCallCount++;
		})
			->catch(self::mockCallable(1, $exception));

		$promiseContainer->reject($exception);
		self::assertEquals(0, $fulfilledCallCount);
	}

	/**
	 * This behaviour might not look correct at first, but it is
	 * made this way to be compatible with the W3C Promise specification.
	 *
	 * An exception being thrown within the then() onFulfilled callback
	 * will not trigger the optional onRejected callback, but it should
	 * be caught in the catch() function.
	 *
	 * @see https://codepen.io/g105b/pen/vYXPoGG?editors=0011
	 */
	public function testRejectIfFulfillerThrowsException() {
		$exception = new Exception("Thrown from within onFulfilled!");
		$promiseContainer = self::getTestPromiseContainer();

		$sut = $promiseContainer->getPromise();
		$sut->then(
			function() use ($exception) {
				throw $exception;
			},
			self::mockCallable(0),
		)->catch(
			self::mockCallable(1, $exception)
		);

		$promiseContainer->resolve("success");
	}

	public function testRejectIfRejecterThrowsException() {
		$exception = new Exception("another-test");
		$caughtExceptions = [];

		$promiseContainer = self::getTestPromiseContainer();

		$sut = $promiseContainer->getPromise();
		$sut->then(self::mockCallable(0))
			->catch(function(Throwable $reason) use ($exception) {
				throw $exception;
			})
			->then(self::mockCallable(0))
			->catch(function(Throwable $reason) use (&$caughtExceptions) {
				array_push($caughtExceptions, $reason);
			});

		$promiseContainer->reject($exception);
		self::assertCount(1, $caughtExceptions);
	}

	public function testLatestResolvedValueUsedOnFulfillment() {
		$promiseContainer = $this->getTestPromiseContainer();
		$onFulfilled = self::mockCallable(1, "example1");
		$onRejected = self::mockCallable(0);

		$sut = $promiseContainer->getPromise();
		$sut->then(
			$onFulfilled,
			$onRejected
		);

		$promiseContainer->resolve("example1");
		$promiseContainer->resolve("example2");
	}

	public function testPreResolvedPromiseInvokesOnFulfill() {
		$promiseContainer = $this->getTestPromiseContainer();
		$onFulfilled = self::mockCallable(1);
		$onRejected = self::mockCallable(0);

		$sut = $promiseContainer->getPromise();
		$sut->then(
			$onFulfilled,
			$onRejected
		);
		$promiseContainer->resolve("example");
	}

	public function testThenResultForwardedWhenOnFulfilledIsNull() {
		$message = "example resolution message";

		$promiseContainer = $this->getTestPromiseContainer();

		$caughtResolutions = [];
		$caughtReasons = [];

		$sut = $promiseContainer->getPromise();
		$sut->then(function(string $resolved) use (&$caughtResolutions) {
			array_push($caughtResolutions, $resolved);
			return $resolved;
		})->catch(function(Throwable $reason) use (&$caughtReasons) {
			array_push($caughtReasons, $reason);
		})->then(function(string $resolved) use (&$caughtResolutions) {
			array_push($caughtResolutions, $resolved);
			return $resolved;
		})->catch(function(Throwable $reason) use (&$caughtReasons) {
			array_push($caughtReasons, $reason);
		});

		$promiseContainer->resolve($message);
		self::assertCount(2, $caughtResolutions);
		self::assertEmpty($caughtReasons);
	}

	public function testThenCallbackResultForwarded() {
		$message = "Hello";
		$messageConcat = "PHP.Gt";
		$expectedResolvedMessage = "$message, $messageConcat!!!";

		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();

		$onFulfilled = self::mockCallable(
			1,
			$expectedResolvedMessage
		);
		$onRejected = self::mockCallable(0);

		$sut->then(function(string $message) use ($messageConcat) {
			return "$message, $messageConcat";
		})
			->then(function(string $message) {
				return "$message!!!";
			})
			->then($onFulfilled)
			->catch($onRejected);

		$promiseContainer->resolve($message);
	}

	public function testThenCallbackResultStoppedWhenNullReturn() {
		$concatMessages1 = "";
		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();
		$sut->then(function(string $message) use (&$concatMessages1) {
			$concatMessages1 .= $message;

			if($message === "STOP") {
				return null;
			}

			return "MORE";
		})->then(function(string $message) use (&$concatMessages1) {
			$concatMessages1 .= $message;
		});

		$promiseContainer->resolve("HELLO");
		self::assertEquals("HELLOMORE", $concatMessages1);

		$concatMessages2 = "";
		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();
		$sut->then(function(string $message) use (&$concatMessages2) {
			$concatMessages2 .= $message;

			if($message === "STOP") {
				return null;
			}

			return "MORE";
		})->then(function(string $message) use (&$concatMessages2) {
			$concatMessages2 .= $message;
		});

		$promiseContainer->resolve("STOP");
		self::assertEquals("STOP", $concatMessages2);
	}

	/**
	 * A rejected promise should forward its rejection to the end of the
	 * promise chain.
	 * @see https://codepen.io/g105b/pen/BaLENgX?editors=0011
	 */
	public function testThenRejectionCallbackResultForwarded() {
		$promiseContainer = $this->getTestPromiseContainer();
		$expectedException = new Exception("Reject the whole chain");

		$fulfilledCallCount = 0;

		$sut = $promiseContainer->getPromise();
		$sut->then(function($value) use (&$fulfilledCallCount) {
			$fulfilledCallCount++;
		})
			->then(function($value) use (&$fulfilledCallCount) {
				$fulfilledCallCount++;
			})
			->then(function($value) use (&$fulfilledCallCount) {
				$fulfilledCallCount++;
			})
			->catch(self::mockCallable(1, $expectedException));

		$promiseContainer->reject($expectedException);
		self::assertEquals(0, $fulfilledCallCount);
	}

	/**
	 * If a rejection returns a value, the next chained promise should
	 * resolve with the value.
	 * @see https://codepen.io/g105b/pen/LYRvpNJ?editors=0011
	 */
	public function testThenProvidedResolvedValueAfterRejectionReturnsValue() {
		$message = "If a rejection returns a value, the next chained "
			. "promise should resolve with the value";

		$exception = new Exception("Test Exception!");

		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();
		$sut->then(self::mockCallable(0))
			->catch(fn() => $message)
			->then(self::mockCallable(1, $message))
			->catch(self::mockCallable(0));

		$promiseContainer->reject($exception);
	}

	public function testCatchCalledForRejectedPromise() {
		$exception = new Exception("Example");
		$promiseContainer = $this->getTestPromiseContainer();

		$sut = $promiseContainer->getPromise();
		$sut->catch(
			self::mockCallable(1, $exception),
		);

		$promiseContainer->reject($exception);
	}

	public function testCatchRejectionReasonIdenticalToRejectionException() {
		$exception = new Exception("Example");
		$promiseContainer = $this->getTestPromiseContainer();

		$onRejected = self::mockCallable(1, $exception);

		$sut = $promiseContainer->getPromise();
		$sut->catch(function($reason) use ($onRejected) {
			call_user_func($onRejected, $reason);
		});
		$promiseContainer->reject($exception);
	}

	public function testCatchRejectionHandlerIsCalledByTypeHintedOnRejectedCallback() {
		$exception = new PromiseException("Example");
		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();

		$onRejected = self::mockCallable(1, $exception);

		$sut->catch(function(PromiseException $reason) use ($onRejected) {
			call_user_func($onRejected, $reason);
		});

		$promiseContainer->reject($exception);
	}

	public function testCatchRejectionWhenExceptionIsThrownInResolutionFunction() {
		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();

		$expectedResolution = "Resolve!";
		$caughtResolutions = [];
		$expectedReason = new RuntimeException("This is expected");
		$caughtReasons = [];

		$sut->then(function($value) use ($expectedReason, &$caughtResolutions) {
			array_push($caughtResolutions, $value);
			throw $expectedReason;
		})->catch(function(Throwable $reason) use (&$caughtReasons) {
			array_push($caughtReasons, $reason);
		});

		$promiseContainer->resolve($expectedResolution);

		self::assertCount(1, $caughtResolutions);
		self::assertSame($expectedResolution, $caughtResolutions[0]);
		self::assertCount(1, $caughtReasons);
		self::assertSame($expectedReason, $caughtReasons[0]);
	}

	public function testCatchRejectionWhenExceptionIsThrownInResolutionFunctionUsingNestedPromises() {
		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();

		$newDeferred = new Deferred();
		$newPromise = $newDeferred->getPromise();

		$expectedResolution = "Resolve!";
		$caughtResolutions = [];
		$expectedReason = new RuntimeException("This is expected");
		$caughtReasons = [];

		$sut->then(function($value) use ($expectedReason) {
			throw $expectedReason;
		})->catch(function(Throwable $reason) use ($newDeferred) {
			$newDeferred->reject($reason);
		});

		$newPromise->then(function($value) use ($expectedReason, &$caughtResolutions) {
			array_push($caughtResolutions, $value);
		})->catch(function(Throwable $reason) use (&$caughtReasons) {
			array_push($caughtReasons, $reason);
		});

		$promiseContainer->resolve($expectedResolution);

		self::assertEmpty($caughtResolutions);
		self::assertCount(1, $caughtReasons);
		self::assertSame($expectedReason, $caughtReasons[0]);
	}

	public function testCatchNotCalledOnFulfilledPromise() {
		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();
		$sut->catch(self::mockCallable(0));
		$promiseContainer->resolve("example");
	}

	public function testFinallyDoesNotBlockOnFulfilled() {
		$expectedValue = "Don't break promises!";
		$promiseContainer = $this->getTestPromiseContainer();

		$sut = $promiseContainer->getPromise();
		$sut->finally(fn() => "example123")
			->then(self::mockCallable(1, $expectedValue));

		$promiseContainer->resolve($expectedValue);
	}

	public function testFinallyDoesNotBlockOnRejected() {
		$exception = new Exception();
		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();
		$sut->finally(function() {})
			->catch(self::mockCallable(1, $exception));
		$promiseContainer->reject($exception);
	}

	public function testFinallyDoesNotBlockOnRejectedWhenReturnsScalar() {
		$exception = new Exception();
		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();
		$sut->finally(function() {
			return "Arbitrary scalar value";
		})->catch(
			self::mockCallable(1, $exception),
		);
		$promiseContainer->reject($exception);
	}

	public function testFinallyPassesThrownException() {
		$exception1 = new Exception("First");
		$promiseContainer = $this->getTestPromiseContainer();

		self::expectException(Exception::class);
		self::expectExceptionMessage("Second");
		$sut = $promiseContainer->getPromise();
		$sut->finally(function(mixed $resolvedValueOrRejectedReason) use ($exception1) {
			self::assertSame($resolvedValueOrRejectedReason, $exception1);
			throw new Exception("Second");
		})
			->then(self::mockCallable(0))
			->catch(self::mockCallable(1, $exception1));
		$promiseContainer->reject($exception1);
	}

	public function testFinallyCanReturnPromise() {
		$finallyLog = [];

		$otherPromiseContainer = $this->getTestPromiseContainer();
		$otherPromise = $otherPromiseContainer->getPromise();

		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();
		$sut->finally(function(mixed $resolvedValueOrRejectedReason) use($otherPromise, &$finallyLog) {
			array_push($finallyLog, $resolvedValueOrRejectedReason);
			return $otherPromise;
		})->finally(function(mixed $resolvedValueOrRejectedReason) use($otherPromise, &$finallyLog) {
			array_push($finallyLog, $resolvedValueOrRejectedReason);
		});
		$promiseContainer->resolve("test");
		self::assertCount(2, $finallyLog);
		self::assertSame("test", $finallyLog[0]);
		self::assertNull($finallyLog[1]);
	}

	public function testOnRejectedCalledWhenFinallyThrows() {
		$exception = new PromiseException("Oh dear, oh dear");
		$promiseContainer = $this->getTestPromiseContainer();

		self::expectException(PromiseException::class);
		self::expectExceptionMessage("Oh dear, oh dear");
		$sut = $promiseContainer->getPromise();
		$sut->finally(function() use ($exception) {
			throw $exception;
		})->then(
			self::mockCallable(1, "Example resolution"),
			self::mockCallable(0)
		);
		$promiseContainer->resolve("Example resolution");
	}

	public function testGetStatePending() {
		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();
		self::assertEquals(
			PromiseState::PENDING,
			$sut->getState()
		);
	}

	public function testGetStateFulfilled() {
		$promiseContainer = $this->getTestPromiseContainer();
		$promiseContainer->resolve("Example resolution");
		$sut = $promiseContainer->getPromise();

		self::assertEquals(
			PromiseState::RESOLVED,
			$sut->getState()
		);
	}

	public function testGetStateRejected() {
		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();
		$sut->catch(function(Throwable $throwable) {});

		$promiseContainer->reject(new Exception("Example rejection"));

		self::assertEquals(
			PromiseState::REJECTED,
			$sut->getState()
		);
	}

	/**
	 * This test is almost identical to the next one. Inside a try-catch
	 * block, it executes a then-catch chain. It asserts that the catch
	 * callback is provided the expected exception, and that the exception
	 * does not bubble out and into the catch block.
	 */
	public function testCatchMethodNotBubblesThrowables() {
		$expectedException = new Exception("Test exception");
		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();
		$onRejected = self::mockCallable(1, $expectedException);

		$exception = null;
		try {
			$sut->then(function() use ($expectedException) {
				throw $expectedException;
			})
				->catch($onRejected);
		} catch(Throwable $exception) {
		}

		$promiseContainer->resolve("test");
		self::assertNull($exception);
	}

	/**
	 * This test tests the opposite of the previous one: if there is no
	 * catch function in the promise chain, an exception should bubble up
	 * and be caught by the try-catch block.
	 */
	public function testNoCatchMethodBubblesThrowables() {
		$expectedException = new Exception("Test exception");
		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();
		$sut->then(function() use ($expectedException) {
			throw $expectedException;
		});

		$exception = null;
		try {
			$promiseContainer->resolve("test");
		} catch(Throwable $exception) {
		}

		self::assertSame($expectedException, $exception);
	}

	public function testNoCatchMethodBubblesThrowables_internalRejection() {
		$expectedException = new Exception("Test exception");
		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();

		$exception = null;
		try {
			$sut->then(function(string $message) use ($sut, $promiseContainer, $expectedException) {
				$sut->then(function($resolvedValue) use ($promiseContainer, $expectedException) {
					$promiseContainer->reject($expectedException);
				});
				return $sut;
			});

			$promiseContainer->resolve("test");
		} catch(Throwable $exception) {
		}

		self::assertSame($expectedException, $exception);
	}

	public function testCatchCanReturnPromise() {
		$catchLog = [];
		$finallyLog = [];

		$otherPromiseContainer = $this->getTestPromiseContainer();
		$otherPromise = $otherPromiseContainer->getPromise();
		$otherPromiseContainer->resolve("test");

		$promiseContainer = $this->getTestPromiseContainer();
		$sut = $promiseContainer->getPromise();
		$sut->catch(function(Throwable $rejectedReason) use($otherPromise, &$catchLog) {
			array_push($catchLog, $rejectedReason);
			return $otherPromise;
		})->finally(function(mixed $received) use(&$finallyLog) {
			array_push($finallyLog, $received);
		});
		$promiseContainer->resolve($sut);
		self::assertCount(1, $catchLog);
		self::assertCount(1, $finallyLog);
		self::assertInstanceOf(PromiseResolvedWithAnotherPromiseException::class, $catchLog[0]);
		self::assertSame("test", $finallyLog[0]);
	}

	public function testFulfilledReturnsNewPromiseThatIsResolved() {
		$numberPromiseContainer = $this->getTestPromiseContainer();
		$numberPromise = $numberPromiseContainer->getPromise();

		$messagePromiseContainer = $this->getTestPromiseContainer();
		$messagePromise = $messagePromiseContainer->getPromise();

		$numberToResolveWith = null;
		$actualMessageReceived = null;

// The first onFulfilled takes the number to process, and returns a new promise
// which should resolve to a message containing the number.
		$numberPromise
			->then(function(int $number) use ($messagePromiseContainer, $messagePromise, &$numberToResolveWith) {
				$numberToResolveWith = $number;
				return $messagePromise;
			})
			->then(
				function(string $message) use(&$actualMessageReceived) {
					$actualMessageReceived = $message;
				}
			);

		$numberPromiseContainer->resolve(105);
		$messagePromiseContainer->resolve("Your number is $numberToResolveWith");
		self::assertEquals("Your number is 105", $actualMessageReceived);
	}

	/**
	 * Similar test to the one above, but done in a different style.
	 * Closer to a real-world usage, this emulates getting a person's
	 * address from their name, from an external list.
	 */
	public function testFulfilledReturnsNewPromiseThatIsResolved2() {
// Our fake data source that will be "searched" by a deferred task (not using an
// actual Deferred object, but instead, longhand performing a loop outside
// of the Promise callback).
		$addressBook = [
			"Adrian Appleby" => "16B Acorn Grove",
			"Bentley Buttersworth" => "59 Brambetwicket Drive",
			"Cacey Coggleton" => "10 Cambridge Road",
		];
// The search term used to resolve the first promise with.
		$searchTerm = null;
// We will store any parameters received by the promise fulfilment callbacks.
		$receivedNames = [];
		$receivedAddresses = [];

// All references to the various callbacks, usually handled by a Deferred:
		$fulfill = null;
		$reject = null;
		$complete = null;
		$innerFulfill = null;
		$innerReject = null;
		/** @var null|callable $innerComplete */
		$innerComplete = null;
		$innerPromise = null;

		$sut = new Promise(function($f, $r, $c) use (&$fulfill, &$reject, &$complete) {
			$fulfill = $f;
			$reject = $r;
			$complete = $c;
		});

// Define asynchronous behaviour:
		$sut->then(function(string $name) use (&$innerFulfil, &$innerReject, &$innerComplete, &$innerPromise, &$searchTerm, &$receivedNames) {
			array_push($receivedNames, $name);
			$searchTerm = $name;

			$innerPromise = new Promise(function($f, $r, $c) use (&$innerFulfil, &$innerReject, &$innerComplete) {
				$innerFulfil = $f;
				$innerReject = $r;
				$innerComplete = $c;
			});
			return $innerPromise;
		})->then(function(string $address) use (&$receivedAddresses) {
			array_push($receivedAddresses, $address);
		});

// This is the "user code" that initiates the search.
// Completing the promise resolution with "Butter" will call the Promise's
// onFulfilled callback, thus our $searchTerm variable should contain "Butter".
		call_user_func($fulfill, "Butter");
		call_user_func($complete);
		self::assertEquals("Butter", $searchTerm);

// This is the deferred task for the search:
		foreach($addressBook as $name => $address) {
			if(strstr($name, $searchTerm)) {
				call_user_func($innerFulfil, $address);
				call_user_func($innerComplete);
			}
		}

		self::assertCount(1, $receivedNames);
		self::assertCount(1, $receivedAddresses);
		self::assertEquals($addressBook["Bentley Buttersworth"], $receivedAddresses[0]);
	}

	/**
	 * This simulates the type of promise that's created and returned from
	 * functions such as BodyResponse::json()
	 */
	public function testCustomPromise_resolve() {
		$newPromise = new CustomPromise();
		$deferred = new Deferred();
		$deferredPromise = $deferred->getPromise();
		$deferredPromise->then(function($resolvedValue) use ($newPromise) {
			$newPromise->resolve($resolvedValue);
		}, function($rejectedValue) use ($newPromise) {
			$newPromise->reject($rejectedValue);
		});

		$resolution = null;
		$rejection = null;

		$newPromise->then(function($resolvedValue) use (&$resolution) {
			$resolution = $resolvedValue;
		}, function($rejectedValue) use (&$rejection) {
			$rejection = $rejectedValue;
		});

		// Do the actual deferred work:
		$deferred->resolve("success");

		self::assertSame("success", $resolution);
		self::assertNull($rejection);
		self::assertSame(PromiseState::RESOLVED, $newPromise->getState());
	}

	public function testCustomPromise_reject() {
		$customPromise = new CustomPromise();

		$deferred = new Deferred();
		$deferredPromise = $deferred->getPromise();
		$deferredPromise->then(function($resolvedValue) use ($customPromise) {
			$customPromise->resolve($resolvedValue);
		})->catch(function($rejectedValue) use ($customPromise) {
			$customPromise->reject($rejectedValue);
		});

		$resolution = null;
		$rejection = null;

		$customPromise->then(function($resolvedValue) use (&$resolution) {
			$resolution = $resolvedValue;
		})->catch(function($rejectedValue) use (&$rejection) {
			$rejection = $rejectedValue;
		});

		$exception = new RuntimeException("OH NO");
		// The deferred work can fail, throwing the rejection:
		$deferred->reject($exception);

		self::assertNull($resolution);
		self::assertSame($exception, $rejection);
		self::assertSame(PromiseState::REJECTED, $customPromise->getState());
	}

	public function testPromise_rejectChain() {
		$thenCalls = [];
		$catchCalls = [];

		$deferred = new Deferred();
		$deferredPromise = $deferred->getPromise();
		$deferredPromise->then(function($resolvedValue) use (&$thenCalls) {
			array_push($thenCalls, $resolvedValue);
		})->catch(function(Throwable $reason) use (&$catchCalls) {
			array_push($catchCalls, $reason);
		});

		$innerDeferred = new Deferred();
		$innerPromise = $innerDeferred->getPromise();

		$rejection = new Exception("test rejection");
		$innerPromise->then(function(string $message) use ($rejection) {
			if(!$message) {
				throw $rejection;
			}
		})->catch(function(Throwable $reason) use ($deferred) {
			$deferred->reject($reason);
		});

		$innerDeferred->resolve("");

		self::assertCount(0, $thenCalls);
		self::assertCount(1, $catchCalls);
	}

	public function testPromise_notThrowWhenNoCatch():void {
		$expectedException = new RuntimeException("This should be passed to the catch function");
		$caughtReasons = [];

		$deferred = new Deferred();
		$deferredPromise = $deferred->getPromise();
		$deferredPromise->then(function(string $message) use ($expectedException) {
			if($message === "error") {
				throw $expectedException;
			}
		})->catch(function(Throwable $reason) use (&$caughtReasons) {
			array_push($caughtReasons, $reason);
		});

		$deferred->resolve("error");
		self::assertCount(1, $caughtReasons);
		self::assertSame($expectedException, $caughtReasons[0]);
	}

	public function testPromise_throwWhenNoCatch():void {
		$expectedException = new RuntimeException("There was an error!");

		$deferred = new Deferred();
		$deferredPromise = $deferred->getPromise();
		$deferredPromise->then(function(string $message) use ($expectedException) {
			if($message === "error") {
				throw $expectedException;
			}
		});

		self::expectException(RuntimeException::class);
		self::expectExceptionMessage("There was an error!");
		$deferred->resolve("error");
	}

	public function testPromise_catchMethodCorrectType():void {
		$deferred = new Deferred();
		$promise = $deferred->getPromise();
		$resolvedValue = null;
		$rejectedReason = null;

		$promise->then(function(string $value) use(&$resolvedValue) {
			$resolvedValue = $value;
		})->catch(function(ValueError $valueError) use(&$rejectedReason) {
			$rejectedReason = $valueError;
		})->catch(function(ArithmeticError $arithmeticError) use(&$rejectedReason) {
			$rejectedReason = $arithmeticError;
		});

		$triggeredError = new ArithmeticError("something bad happened");
		$deferred->reject($triggeredError);
		self::assertNull($resolvedValue);
		self::assertSame($triggeredError, $rejectedReason);
	}

	protected function getTestPromiseContainer():TestPromiseContainer {
		$resolveCallback = null;
		$rejectCallback = null;
		$completeCallback = null;

		$promise = new Promise(function($resolve, $reject, $complete)
		use (&$resolveCallback, &$rejectCallback, &$completeCallback) {
			$resolveCallback = $resolve;
			$rejectCallback = $reject;
			$completeCallback = $complete;
		});

		return new TestPromiseContainer(
			$promise,
			$resolveCallback,
			$rejectCallback,
			$completeCallback
		);
	}

	/** @return MockObject|callable */
	protected function mockCallable(
		int $numCalls = null,
		...$expectedParameters
	):MockObject {
		$mock = self::getMockBuilder(
			stdClass::class
		)->addMethods([
			"__invoke"
		])->getMock();

		if(!is_null($numCalls)) {
			$expectation = $mock->expects(self::exactly($numCalls))
				->method("__invoke");

			if(!empty($expectedParameters)) {
				foreach($expectedParameters as $p) {
					$expectation->with(self::identicalTo($p));
				}
			}
		}

		return $mock;
	}

	/** @return MockObject|callable */
	protected function mockCallableThrowsException(
		Exception $exception,
		int $numCalls,
		...$expectedParameters
	):MockObject {
		$mock = self::mockCallable($numCalls, ...$expectedParameters);
		$mock->method("__invoke")
			->will(self::throwException($exception));
		return $mock;
	}
}

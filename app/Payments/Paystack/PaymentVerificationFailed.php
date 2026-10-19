<?php

namespace App\Payments\Paystack;

use RuntimeException;

/** A webhook arrived without a valid signature. Never acted on. */
class PaymentVerificationFailed extends RuntimeException {}

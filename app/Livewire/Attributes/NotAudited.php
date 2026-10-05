<?php

declare(strict_types=1);

namespace App\Livewire\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class NotAudited {}

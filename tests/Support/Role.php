<?php

declare(strict_types=1);

namespace Rasuvaeff\Schema\Tests\Support;

enum Role: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';
}

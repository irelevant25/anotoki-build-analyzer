<?php

namespace App;

/**
 * The version people see: the newest entry of frontend/public/changelog.json, and what GET /api/meta
 * answers - MAJOR.MINOR.PATCH (big changes, new features, bug fixes). Raise it together with that entry and
 * frontend/package.json's version.
 */
final class Version
{
    public const CURRENT = '0.10.0';
}

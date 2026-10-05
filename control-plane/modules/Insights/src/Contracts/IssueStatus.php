<?php

namespace Falak\Insights\Contracts;

enum IssueStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Ignored = 'ignored';
}

<?php

namespace Falak\Templates\Domain;

enum TemplateSource: string
{
    /** Curated, versioned with Falak (templates/ at the repository root). */
    case Catalog = 'catalog';
    /** An organization's own template (stored in the database). */
    case Custom = 'custom';
}

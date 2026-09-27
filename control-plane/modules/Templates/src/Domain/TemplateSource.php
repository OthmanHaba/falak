<?php

namespace Kiln\Templates\Domain;

enum TemplateSource: string
{
    /** Curated, versioned with Kiln (templates/ at the repository root). */
    case Catalog = 'catalog';
    /** An organization's own template (stored in the database). */
    case Custom = 'custom';
}

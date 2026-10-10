<?php

return [
    // The organization that operates this Falak and owns the preview domain (Settings → Previews): the one install.sh
    // records for disaster recovery (its id). Empty: the only organization of a single-organization install.
    'operator_organization' => env('FALAK_DR_ORGANIZATION', ''),
];

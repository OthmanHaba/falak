<?php

return [
    // The organization that operates this Falak and owns the preview domain (Settings → Previews). Empty: the oldest
    // organization (the one `falak-ctl admin create` made first).
    'operator_organization' => env('FALAK_PREVIEWS_OPERATOR_ORGANIZATION'),
];

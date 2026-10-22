<?php

// The package's webhook route is not used here; keep it off so no unauthenticated route is registered.
return [
    'routes' => (bool) env('RESEND_ROUTES', false),
];

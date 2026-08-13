<?php

it('redirects the root to the frontend', function () {
    $this->get('/')->assertRedirect(config('app.frontend_url'));
});

it('responds on the health endpoint', function () {
    $this->get('/up')->assertOk();
});

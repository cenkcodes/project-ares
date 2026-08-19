<?php

it(
    'redirects first time visitor to age verification',
    function () {
        $response =
            $this->get('/');

        $response->assertRedirect(
            route('age-gate.show')
        );
    }
);

<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('subscriptions:check')->everyThirtyMinutes();

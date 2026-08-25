<?php

namespace App\Http\Requests;

use Illuminate\Database\Eloquent\Model;

/**
 * Runs the ownership check inside the Form Request.
 *
 * A Form Request authorises before it validates, so denying here means another
 * account's record answers "forbidden" rather than leaking a 422 that confirms
 * the record exists and describes what the payload got wrong.
 */
trait AuthorizesRouteModel
{
    /**
     * Authorise the route-bound parent, if there is one.
     *
     * @param  string  $parameter  route parameter holding the model
     * @param  string  $ability  policy ability to check
     */
    protected function allowsRouteModel(string $parameter, string $ability = 'update'): bool
    {
        $model = $this->route($parameter);

        // Nothing bound (a store action) — the controller scopes to the user.
        if (! $model instanceof Model) {
            return true;
        }

        return $this->user()?->can($ability, $model) ?? false;
    }
}

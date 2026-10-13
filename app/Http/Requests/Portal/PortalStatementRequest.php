<?php

namespace App\Http\Requests\Portal;

use App\Http\Requests\Admin\AccountStatementRequest;

/**
 * Same period rules as staff; ownership is enforced in the controller from the portal session.
 */
class PortalStatementRequest extends AccountStatementRequest
{
    public function authorize(): bool
    {
        return true;
    }
}

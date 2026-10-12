<?php

namespace App\Http\Requests\Admin;

/**
 * Optional period for a party-wide statement; same rules as an account statement, authorized by viewing the party.
 */
class PartyStatementRequest extends AccountStatementRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('party')) ?? false;
    }
}

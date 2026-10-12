<?php

/*
 * App UI strings. Every key here needs a Bengali twin in lang/bn/app.php.
 */
return [
    'dashboard' => 'Dashboard',
    'today' => 'Today',
    'logout' => 'Sign out',
    'no_role' => 'This account has no role yet. Ask an admin to assign one.',
    'save' => 'Save',
    'cancel' => 'Cancel',
    'edit' => 'Edit',
    'account_inactive' => 'This account is deactivated. Contact an admin.',
    'roles' => [
        'admin' => 'Admin',
        'manager' => 'Manager',
        'accountant' => 'Accountant',
        'field_officer' => 'Field officer',
    ],
    'statuses' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
    ],
    'users' => [
        'title' => 'Users',
        'create' => 'New user',
        'edit' => 'Edit user',
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Mobile number',
        'phone_hint' => '11 digits, e.g. 01711223344',
        'password' => 'Password',
        'password_confirmation' => 'Confirm password',
        'password_keep_hint' => 'Leave blank to keep the current password.',
        'role' => 'Role',
        'status' => 'Status',
        'actions' => 'Actions',
        'empty' => 'No users yet.',
        'created' => 'User created.',
        'updated' => 'User updated.',
        'cannot_demote_self' => 'You cannot remove your own admin role.',
        'cannot_deactivate_self' => 'You cannot deactivate your own account.',
    ],
];

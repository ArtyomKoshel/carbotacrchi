<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/** Admins sign in with a username (admin_users has no email). */
class Login extends BaseLogin
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getUsernameFormComponent(),
            $this->getPasswordFormComponent(),
        ]);
    }

    protected function getUsernameFormComponent(): Component
    {
        return TextInput::make('username')
            ->label('Логин')
            ->required()
            ->autocomplete('username')
            ->autofocus();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [
            'username' => trim((string) $data['username']),
            'password' => $data['password'],
        ];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.username' => 'Неверный логин или пароль',
        ]);
    }
}

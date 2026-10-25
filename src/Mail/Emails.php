<?php

declare(strict_types=1);

namespace SiteAuth\Mail;

/**
 * Modelos dos e-mails da aplicação, em português: texto simples e HTML básico.
 */
final class Emails
{
    public static function verificarEmail(string $nome, string $para, string $link): Email
    {
        $texto = "Olá, {$nome}.\n\n"
            . "Para confirmar que este endereço de e-mail é seu, abra o link abaixo. Ele vale por 24 horas:\n\n{$link}\n\n"
            . "Se você não criou uma conta, ignore este e-mail.\n";

        $html = '<p>Olá, ' . self::e($nome) . '.</p>'
            . '<p>Para confirmar que este endereço de e-mail é seu, use o link abaixo. Ele vale por 24 horas.</p>'
            . '<p><a href="' . self::e($link) . '">Confirmar meu e-mail</a></p>'
            . '<p>Se você não criou uma conta, ignore este e-mail.</p>';

        return new Email($para, 'Confirme seu e-mail', $texto, $html);
    }

    public static function recuperarSenha(string $nome, string $para, string $link): Email
    {
        $texto = "Olá, {$nome}.\n\n"
            . "Recebemos um pedido para redefinir a senha da sua conta. Para criar uma nova senha, abra o link abaixo. "
            . "Ele vale por 30 minutos e só pode ser usado uma vez:\n\n{$link}\n\n"
            . "Se você não fez esse pedido, ignore este e-mail. Sua senha continua a mesma.\n";

        $html = '<p>Olá, ' . self::e($nome) . '.</p>'
            . '<p>Recebemos um pedido para redefinir a senha da sua conta. Para criar uma nova senha, use o link abaixo. '
            . 'Ele vale por 30 minutos e só pode ser usado uma vez.</p>'
            . '<p><a href="' . self::e($link) . '">Redefinir minha senha</a></p>'
            . '<p>Se você não fez esse pedido, ignore este e-mail. Sua senha continua a mesma.</p>';

        return new Email($para, 'Redefinição de senha', $texto, $html);
    }

    public static function senhaAlterada(string $nome, string $para, string $quando, ?string $linkRecuperacao): Email
    {
        $texto = "Olá, {$nome}.\n\n"
            . "A senha da sua conta foi alterada em {$quando}. Se foi você, não precisa fazer nada.\n\n"
            . "Se não foi você, redefina a senha agora e revise o acesso à sua conta de e-mail";
        $html = '<p>Olá, ' . self::e($nome) . '.</p>'
            . '<p>A senha da sua conta foi alterada em ' . self::e($quando) . '. Se foi você, não precisa fazer nada.</p>'
            . '<p>Se não foi você, redefina a senha agora e revise o acesso à sua conta de e-mail';

        if ($linkRecuperacao !== null) {
            $texto .= ":\n\n{$linkRecuperacao}\n";
            $html .= ': <a href="' . self::e($linkRecuperacao) . '">redefinir senha</a>.</p>';
        } else {
            $texto .= ".\n";
            $html .= '.</p>';
        }

        return new Email($para, 'Sua senha foi alterada', $texto, $html);
    }

    private static function e(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

<?php

namespace App\Modules\Core\Contracts\Exceptions;

/**
 * MailSender could not hand a mail to Microsoft Graph (authentication,
 * permissions, throttling, invalid recipient, network). The mail was not
 * sent; the Graph error, when any, is the previous exception. Messages
 * never include credentials or tokens.
 */
final class MailNotSent extends ContractException {}

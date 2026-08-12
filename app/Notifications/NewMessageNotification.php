<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Apn\ApnMessage;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\AndroidConfig;
use NotificationChannels\Fcm\Resources\AndroidFcmOptions;
use NotificationChannels\Fcm\Resources\AndroidNotification;
use NotificationChannels\Fcm\Resources\ApnsConfig;
use NotificationChannels\Fcm\Resources\ApnsFcmOptions;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

class NewMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $message;

    public function __construct($message)
    {
        $this->message = $message;
    }

    public function via($notifiable)
    {
        return [FcmChannel::class, ApnChannel::class];
    }

    // public function toFcm($notifiable)
    // {
    //     // Estructura compatible con React Native
    //     return FcmMessage::create()
    //         ->setData([
    //             'conversation_id' => (string) $this->message->conversation_id,
    //             'message_id' => (string) $this->message->id,
    //             'user_id' => (string) $this->message->user_id,
    //             'type' => 'new_message',
    //             'click_action' => 'FLUTTER_NOTIFICATION_CLICK' // Para Flutter, cambiar según tu config en React Native
    //         ])
    //         ->setNotification(FcmNotification::create()
    //             ->setTitle('Nuevo mensaje')
    //             ->setBody($this->message->user->name.': '.$this->message->body)
    //         )
    //         ->setAndroid(
    //             AndroidConfig::create()
    //                 ->setFcmOptions(AndroidFcmOptions::create()->setAnalyticsLabel('android_notification'))
    //                 ->setNotification(AndroidNotification::create()
    //                     ->setColor('#0A0A0A')
    //                     ->setClickAction('FLUTTER_NOTIFICATION_CLICK')
    //                     ->setChannelId('messages_channel') // Necesario para Android 8+
    //                 )
    //         )
    //         ->setApns(
    //             ApnsConfig::create()
    //                 ->setFcmOptions(ApnsFcmOptions::create()->setAnalyticsLabel('ios_notification'))
    //                 ->setPayload([
    //                     'aps' => [
    //                         'sound' => 'default',
    //                         'badge' => 1,
    //                         'content-available' => 1
    //                     ]
    //                 ])
    //         );
    // }

    public function toApn($notifiable)
    {
        return ApnMessage::create()
            ->title('Nuevo mensaje')
            ->body($this->message->user->name.': '.$this->message->body)
            ->badge(1)
            ->sound('default')
            ->custom('conversation_id', $this->message->conversation_id)
            ->custom('message_id', $this->message->id)
            ->custom('type', 'new_message')
            ->action('view_message', [
                'conversation_id' => $this->message->conversation_id,
                'message_id' => $this->message->id,
            ]);
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $preview = $this->buildPreview();

        return [
            'id' => $this->id,
            'bot_id' => $this->bot_id,
            'bot_version_id' => $this->bot_version_id,
            'whatsapp_account_id' => $this->whatsapp_account_id,
            'whatsapp_user_phone' => $this->whatsapp_user_phone,
            'whatsapp_user_name' => $this->whatsapp_user_name,
            'status' => $this->status,
            'assigned_agent_id' => $this->assigned_agent_id,
            'assigned_agent_name' => $this->assignedAgent?->name,
            'message_count' => $this->message_count,
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
            'last_message_at' => $this->last_message_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'duration_formatted' => $this->getFormattedDuration(),
            'unread_count' => $this->unread_count ?? 0,
            'last_message_preview' => $preview['text'],
            'last_message_preview_type' => $preview['type'],

            // Relationships — only included when loaded
            'bot' => $this->whenLoaded('bot', fn () => [
                'id' => $this->bot->id,
                'name' => $this->bot->name,
            ]),
            'whatsapp_account' => $this->whenLoaded('whatsappAccount', fn () => [
                'id' => $this->whatsappAccount->id,
                'name' => $this->whatsappAccount->name,
                'phone_number' => $this->whatsappAccount->phone_number,
                'phone_number_id' => $this->whatsappAccount->phone_number_id,
            ]),
            'latest_message' => $this->whenLoaded(
                'latestMessage',
                fn () => $this->latestMessage
                    ? new MessageResource($this->latestMessage)
                    : null,
            ),
            'context' => $this->whenLoaded('context'),
        ];
    }

    private function buildPreview(): array
    {
        $message = $this->relationLoaded('latestMessage') ? $this->latestMessage : null;

        if (!$message) {
            return ['type' => 'none', 'text' => 'No messages yet'];
        }

        $content = is_array($message->content)
            ? $message->content
            : (json_decode((string) $message->content, true) ?? []);

        $type = $message->message_type;

        return match (true) {
            $type === 'text' => ['type' => 'text', 'text' => $content['text'] ?? ''],

            $type === 'interactive' && ($content['type'] ?? null) === 'list' => ['type' => 'list', 'text' => $content['body']['text'] ?? 'List message'],

            $type === 'interactive' && ($content['type'] ?? null) === 'button' => ['type' => 'buttons', 'text' => $content['body']['text'] ?? 'Button message'],

            $type === 'interactive' && isset($content['response']) => ['type' => 'reply', 'text' => $content['response']['title'] ?? 'Selected an option'],

            $type === 'button' => ['type' => 'reply', 'text' => $content['text'] ?? 'Quick reply'],

            $type === 'image' => ['type' => 'image', 'text' => $content['caption'] ?? 'Photo'],

            $type === 'video' => ['type' => 'video', 'text' => $content['caption'] ?? 'Video'],

            $type === 'audio' => ['type' => 'audio', 'text' => 'Voice message'],

            $type === 'document' => ['type' => 'document', 'text' => $content['caption'] ?? ($content['filename'] ?? 'Document')],

            $type === 'sticker' => ['type' => 'sticker', 'text' => 'Sticker'],

            $type === 'location' => ['type' => 'location', 'text' => $content['name'] ?? 'Location'],

            $type === 'contacts' => ['type' => 'contact', 'text' => $content['contacts'][0]['name']['formatted_name'] ?? 'Contact card'],

            default => ['type' => 'unknown', 'text' => 'Message'],
        };
    }
}
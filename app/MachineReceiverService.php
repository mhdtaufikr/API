<?php

declare (strict_types=1);
interface ReceiverEventStore
{
    /** Return the committed record and whether this is an already stored event. */
    public function store(array $event, array $payload, string $sourceIp): array;
}
final class MachineReceiverService
{
    private ReceiverEventStore $repository;
    private MachineRequestParser $parser;
    private ReceiverAudit $audit;
    public function __construct(ReceiverEventStore $repository, MachineRequestParser $parser, ReceiverAudit $audit)
    {
        $this->repository = $repository;
        $this->parser = $parser;
        $this->audit = $audit;
    }
    public function receive(array $input, string $sourceIp, string $requestId): array
    {
        $safe = [];
        foreach (['cardno', 'd', 'h', 'm'] as $key) {
            $safe[$key] = is_string($input[$key] ?? null) ? substr($input[$key], 0, 1024) : '[missing or non-scalar]';
        }
        $audit = ['request_id' => $requestId, 'source_ip' => $sourceIp, 'php_version' => PHP_VERSION, 'payload' => $safe];
        try {
            $event = $this->parser->parse($input);
        } catch (InvalidArgumentException $error) {
            $this->audit->append($audit + ['status' => 'rejected', 'reason' => $error->getMessage()]);
            return ['http' => 422, 'status' => 'rejected', 'reason' => $error->getMessage()];
        }
        try {
            $record = $this->repository->store($event, $input, $sourceIp);
        } catch (Throwable $error) {
            $this->audit->append($audit + ['status' => 'error', 'reason' => 'Database persistence failed', 'error_class' => get_class($error), 'error_code' => (string) $error->getCode(), 'event_hash' => $event['event_hash']]);
            return ['http' => 503, 'status' => 'error', 'reason' => 'Attendance storage is unavailable. Request was not acknowledged.'];
        }
        $status = $record['duplicate'] ? 'duplicate' : 'stored';
        $this->audit->append($audit + ['status' => $status, 'event_id' => $record['id'], 'event_hash' => $event['event_hash']]);
        return ['http' => 200, 'status' => $status, 'event_id' => $record['id'], 'event' => $event];
    }
}

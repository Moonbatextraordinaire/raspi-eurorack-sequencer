<?php
function send_sequencer_command($command_data) {
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($socket === false) {
        return json_encode([
            "status" => "error",
            "message" => "Socket creation failed: " . socket_strerror(socket_last_error())
        ]);
    }

    socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array("sec" => 2, "usec" => 0));
    socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, array("sec" => 2, "usec" => 0));

    $result = @socket_connect($socket, 'localhost', 5000);
    if ($result === false) {
        return json_encode([
            "status" => "error",
            "message" => "Connection failed: " . socket_strerror(socket_last_error($socket))
        ]);
    }

    $json_command = json_encode($command_data);
    socket_write($socket, $json_command, strlen($json_command));

    $response = socket_read($socket, 2048);
    socket_close($socket);

    if ($response === false) {
        return json_encode([
            "status" => "error",
            "message" => "Failed to read response"
        ]);
    }

    return $response;
}

// Set content type to JSON
header('Content-Type: application/json');

// Handle POST requests for sequence updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get POST data
    $json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);
    
    if ($data === null) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid JSON data received'
        ]);
        exit;
    }

    // Handle sequence updates
    if ($data['type'] === 'update_sequence') {
        // Validate sequence data
        if (!isset($data['channel']) || 
            !isset($data['cv_values']) || 
            !isset($data['gate_states'])) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Missing required sequence data'
            ]);
            exit;
        }

        // Validate CV values (should be between 0 and 1)
        foreach ($data['cv_values'] as $cv) {
            if (!is_numeric($cv) || $cv < 0 || $cv > 1) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Invalid CV value. Must be between 0 and 1'
                ]);
                exit;
            }
        }

        // Validate gate states (should be 0 or 1)
        foreach ($data['gate_states'] as $gate) {
            if (!is_numeric($gate) || ($gate !== 0 && $gate !== 1)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Invalid gate state. Must be 0 or 1'
                ]);
                exit;
            }
        }

        // Send the sequence update command
        $response = send_sequencer_command([
            'type' => 'update_sequence',
            'channel' => $data['channel'],
            'cv_values' => $data['cv_values'],
            'gate_states' => $data['gate_states']
        ]);
        
        echo $response;
        exit;
    }
}

// Handle GET requests for basic commands
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['command'])) {
        $command = $_GET['command'];
        $response = send_sequencer_command(['type' => $command]);
        echo $response;
    } elseif (isset($_GET['tempo'])) {
        $tempo = intval($_GET['tempo']);
        if ($tempo < 40 || $tempo > 300) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Tempo must be between 40 and 300 BPM'
            ]);
            exit;
        }
        $response = send_sequencer_command(['type' => 'tempo', 'value' => $tempo]);
        echo $response;
    } elseif (isset($_GET['get_sequences'])) {
        // Handle request to get current sequence data
        $response = send_sequencer_command(['type' => 'get_sequences']);
        echo $response;
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'No command received'
        ]);
    }
    exit;
}

// Handle unsupported request methods
echo json_encode([
    'status' => 'error',
    'message' => 'Unsupported request method'
]);
?>

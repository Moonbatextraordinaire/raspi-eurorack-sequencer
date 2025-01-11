import sys
import threading
import queue
import socket
import json
from gpiozero import PWMOutputDevice, DigitalOutputDevice
from time import sleep, time
from copy import deepcopy

class NetworkEurorackSequencer:
    def __init__(self, host='0.0.0.0', port=5000):
        # GPIO setup
        self.cv_pin_1 = PWMOutputDevice(18)
        self.gate_pin_1 = DigitalOutputDevice(4)
        self.cv_pin_2 = PWMOutputDevice(24)
        self.gate_pin_2 = DigitalOutputDevice(23)

        # Sequence data with thread-safe access
        self._sequence_lock = threading.Lock()
        self._sequences = {
            'channel_1': {
                "cv_values": [0.1, 0.5, 0.8, 0.3],
                "gate_states": [1, 0, 1, 0]
            },
            'channel_2': {
                "cv_values": [0.4, 0.7, 0.2, 0.9],
                "gate_states": [0, 1, 0, 1]
            }
        }

        # Control variables
        self._tempo_lock = threading.Lock()
        self._running_lock = threading.Lock()
        self._tempo_bpm = 120
        self._running = False

        # Setup command queue and network
        self.command_queue = queue.Queue()
        self.channel_threads = []
        
        # Network setup
        print(f"Initializing network on {host}:{port}")
        self.server_socket = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self.server_socket.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self.server_socket.bind((host, port))
        self.server_socket.listen(1)
        
        self.network_thread = threading.Thread(target=self._handle_network_commands, daemon=True)

    @property
    def tempo_bpm(self):
        with self._tempo_lock:
            return self._tempo_bpm

    @tempo_bpm.setter
    def tempo_bpm(self, value):
        with self._tempo_lock:
            self._tempo_bpm = value

    @property
    def running(self):
        with self._running_lock:
            return self._running

    @running.setter
    def running(self, value):
        with self._running_lock:
            self._running = value

    def get_sequence(self, channel):
        """Thread-safe sequence data access."""
        with self._sequence_lock:
            return deepcopy(self._sequences.get(channel, {}))

    def update_sequence(self, channel, cv_values=None, gate_states=None):
        """Thread-safe sequence data update."""
        with self._sequence_lock:
            if channel in self._sequences:
                if cv_values is not None:
                    self._sequences[channel]['cv_values'] = cv_values
                if gate_states is not None:
                    self._sequences[channel]['gate_states'] = gate_states
                return True
            return False

    def set_cv(self, pwm_device, value):
        """Set CV value using PWM (normalized to 0.0 - 1.0)."""
        pwm_device.value = value

    def set_gate(self, gate_device, state):
        """Set Gate state (ON/OFF)."""
        if state:
            gate_device.on()
        else:
            gate_device.off()

    def play_sequence(self, channel_data, cv_device, gate_device, channel_name):
        """Play sequence for one channel with real-time parameter updates."""
        last_step_time = time()
        step_index = 0

        while self.running:
            current_time = time()
            step_duration = 60 / (self.tempo_bpm * 4)  # 16th notes

            if current_time - last_step_time >= step_duration:
                # Get the latest sequence data
                current_sequence = self.get_sequence(channel_name)

                if current_sequence:  # Check if sequence data exists
                    try:
                        cv = current_sequence['cv_values'][step_index]
                        gate = current_sequence['gate_states'][step_index]
                        print(f"Channel {channel_name}: Step {step_index}, CV={cv}, Gate={gate}")  # Debug output

                        # Set CV value
                        self.set_cv(cv_device, float(cv))

                        # Set Gate value with explicit conversion to boolean
                        self.set_gate(gate_device, bool(int(gate)))

                        last_step_time = current_time
                        step_index = (step_index + 1) % len(current_sequence['cv_values'])
                    except Exception as e:
                        print(f"Error in sequence playback: {e}")

            sleep(0.001)  # Small sleep to prevent CPU overload

    def _process_commands(self):
        """Process commands from the queue."""
        while True:
            try:
                command = self.command_queue.get(timeout=1.0)
                self._handle_command(command)
                self.command_queue.task_done()
            except queue.Empty:
                continue


    def _start_sequencer(self):
    	"""Start the sequencer if not already running."""
    	if not self.running:
            self.running = True
            print("Starting sequencer...")
        
            # Start channel threads with channel names and correct sequence data
            self.channel_threads = [
                threading.Thread(
                    target=self.play_sequence,
                    args=(self._sequences['channel_1'], self.cv_pin_1, self.gate_pin_1, 'channel_1'),
                    daemon=True
            	),
            	threading.Thread(
                    target=self.play_sequence,
                    args=(self._sequences['channel_2'], self.cv_pin_2, self.gate_pin_2, 'channel_2'),
                    daemon=True
            	)
            ]
            for thread in self.channel_threads:
            	thread.start()

    def _handle_command(self, command):
        """Enhanced command handler with sequence updates."""
        cmd_type = command.get('type')
        print(f"Processing command: {cmd_type}")

        if cmd_type == 'start':
            self._start_sequencer()
            return {"status": "success", "message": "Sequencer started"}
        elif cmd_type == 'stop':
            self._stop_sequencer()
            return {"status": "success", "message": "Sequencer stopped"}
        elif cmd_type == 'tempo':
            self._set_tempo(command.get('value', 120))
            return {"status": "success", "message": f"Tempo set to {command.get('value')} BPM"}
        elif cmd_type == 'update_sequence':
            channel = command.get('channel')
            cv_values = command.get('cv_values')
            gate_states = command.get('gate_states')
            success = self.update_sequence(channel, cv_values, gate_states)
            return {
                "status": "success" if success else "error",
                "message": f"Sequence update for {channel}: {'success' if success else 'failed'}"
            }
        elif cmd_type == 'get_sequences':
            return {
                "status": "success",
                "data": {
                    'channel_1': self.get_sequence('channel_1'),
                    'channel_2': self.get_sequence('channel_2')
                }
            }

    def _stop_sequencer(self):
        """Stop the sequencer if running."""
        if self.running:
            self.running = False
            print("Stopping sequencer...")
            
            for thread in self.channel_threads:
                thread.join(timeout=1.0)
            
            for pin in [self.cv_pin_1, self.cv_pin_2]:
                pin.value = 0
            for pin in [self.gate_pin_1, self.gate_pin_2]:
                pin.off()

    def _set_tempo(self, new_tempo):
        """Set new tempo."""
        try:
            new_tempo = int(new_tempo)
            if 30 <= new_tempo <= 300:
                self.tempo_bpm = new_tempo
                print(f"Tempo set to {new_tempo} BPM")
            else:
                print("Tempo must be between 30 and 300 BPM")
        except ValueError:
            print("Invalid tempo value")

    def _handle_network_commands(self):
        """Enhanced network handler with response data."""
        print("Network command handler started")
        while True:
            try:
                print("Waiting for connection...")
                client, addr = self.server_socket.accept()
                print(f"Connection from {addr}")
                with client:
                    data = client.recv(1024).decode('utf-8')
                    if data:
                        try:
                            command = json.loads(data)
                            print(f"Received command: {command}")
                            
                            # Handle command and get any response data
                            response_data = self._handle_command(command)
                            
                            response = {
                                "status": "success",
                                "message": f"Command processed: {command['type']}"
                            }
                            
                            # Add any additional response data
                            if response_data:
                                response["data"] = response_data
                                
                        except json.JSONDecodeError:
                            response = {"status": "error", "message": "Invalid command format"}
                        
                        client.send(json.dumps(response).encode('utf-8'))
            except Exception as e:
                print(f"Network error: {e}")
                continue

    def start(self):
        """Start the sequencer system with network support."""
        print("Starting sequencer with network support on port 5000...")
        self.network_thread.start()
        
        # Start command processing thread
        self.command_thread = threading.Thread(target=self._process_commands, daemon=True)
        self.command_thread.start()
        
        # Keep main thread alive
        try:
            while True:
                sleep(1)
        except KeyboardInterrupt:
            print("\nShutting down...")
            if self.running:
                self.command_queue.put({'type': 'stop'})
            self.server_socket.close()

if __name__ == "__main__":
    sequencer = NetworkEurorackSequencer()
    sequencer.start()

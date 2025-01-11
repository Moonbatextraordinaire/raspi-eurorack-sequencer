<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eurorack Sequencer Control</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            padding: 20px;
            background-color: #f0f0f0;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .controls {
            margin-bottom: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        .sequence-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin: 20px 0;
        }
        .channel {
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        .step {
            padding: 10px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .slider-container {
            margin: 10px 0;
        }
        button { 
            padding: 10px 20px;
            margin: 5px;
            font-size: 16px;
            border-radius: 4px;
            border: none;
            background-color: #007bff;
            color: white;
            cursor: pointer;
        }
        button:hover {
            background-color: #0056b3;
        }
        button.gate-toggle {
            width: 100%;
            background-color: #6c757d;
        }
        button.gate-toggle.active {
            background-color: #28a745;
        }
        input[type="range"] {
            width: 100%;
        }
        .value-display {
            text-align: center;
            margin: 5px 0;
            font-size: 0.9em;
            color: #666;
        }
        #status {
            margin-top: 20px;
            padding: 10px;
            border-radius: 4px;
        }        .debug-info {
            margin-top: 20px;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
            font-family: monospace;
            white-space: pre-wrap;
        }
    </style>
</head>
<body>
    <div class="container">
<h1>Eurorack Sequencer</h1>
        
        <div class="controls">
            <button onclick="sendCommand('start')">Start</button>
            <button onclick="sendCommand('stop')">Stop</button>
            <label for="tempo">Tempo (BPM): </label>
            <input type="number" id="tempo" value="120" min="40" max="300">
            <button onclick="setTempo()">Set Tempo</button>
        </div>
        
        <div id="status"></div>
        
        <div id="channel1" class="channel">
            <h2>Channel 1</h2>
            <div class="sequence-grid" id="channel1-grid"></div>
        </div>
        
        <div id="channel2" class="channel">
            <h2>Channel 2</h2>
            <div class="sequence-grid" id="channel2-grid"></div>
        </div>
    </div>        <div id="debug" class="debug-info"></div>
    </div>

    <script>
        // State management with explicit type conversion
        const sequenceState = {
            channel_1: {
                cv_values: [0.1, 0.5, 0.8, 0.3],
                gate_states: [1, 0, 1, 0]
            },
            channel_2: {
                cv_values: [0.4, 0.7, 0.2, 0.9],
                gate_states: [0, 1, 0, 1]
            }
        };

        function debugLog(message, data) {
            console.log(message, data);
            const debugDiv = document.getElementById('debug');
            debugDiv.textContent = `${message}\n${JSON.stringify(data, null, 2)}`;
        }

        function createSequenceControls() {
            ['channel1', 'channel2'].forEach((channelId, idx) => {
                const grid = document.getElementById(`${channelId}-grid`);
                const channel = `channel_${idx + 1}`;
                
                for (let step = 0; step < 4; step++) {
                    const stepDiv = document.createElement('div');
                    stepDiv.className = 'step';
                    
                    // CV Slider
                    const sliderContainer = document.createElement('div');
                    sliderContainer.className = 'slider-container';
                    
                    const slider = document.createElement('input');
                    slider.type = 'range';
                    slider.min = '0';
                    slider.max = '100';
                    slider.value = Math.round(sequenceState[channel].cv_values[step] * 100);
                    
                    const valueDisplay = document.createElement('div');
                    valueDisplay.className = 'value-display';
                    valueDisplay.textContent = `CV: ${slider.value}%`;
                    
                    // Gate Toggle
                    const gateButton = document.createElement('button');
                    gateButton.className = `gate-toggle ${sequenceState[channel].gate_states[step] ? 'active' : ''}`;
                    gateButton.textContent = sequenceState[channel].gate_states[step] ? 'Gate On' : 'Gate Off';
                    
                    // Event Listeners with explicit type conversion
                    slider.addEventListener('input', (e) => {
                        const value = parseFloat((parseInt(e.target.value) / 100).toFixed(2));
                        sequenceState[channel].cv_values[step] = value;
                        valueDisplay.textContent = `CV: ${e.target.value}%`;
                        updateSequence(channel);
                    });
                    
                    gateButton.addEventListener('click', () => {
                        const newState = !sequenceState[channel].gate_states[step];
                        sequenceState[channel].gate_states[step] = newState ? 1 : 0; // Explicit conversion to 1 or 0
                        gateButton.classList.toggle('active');
                        gateButton.textContent = newState ? 'Gate On' : 'Gate Off';
                        updateSequence(channel);
                    });
                    
                    sliderContainer.appendChild(slider);
                    sliderContainer.appendChild(valueDisplay);
                    stepDiv.appendChild(sliderContainer);
                    stepDiv.appendChild(gateButton);
                    grid.appendChild(stepDiv);
                }
            });
        }

        async function updateSequence(channel) {
            const data = {
                type: 'update_sequence',
                channel: channel,
                cv_values: sequenceState[channel].cv_values.map(v => parseFloat(v.toFixed(2))),
                gate_states: sequenceState[channel].gate_states.map(v => v ? 1 : 0)
            };
            
            debugLog('Sending sequence update:', data);

            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(data)
                });
                const responseData = await response.json();
                debugLog('Server response:', responseData);
                updateStatus(responseData.message);
            } catch (error) {
                console.error('Error:', error);
                updateStatus('Error updating sequence');
            }
        }

        function updateStatus(message) {
            const status = document.getElementById('status');
            status.textContent = message;
            status.style.backgroundColor = '#e8f5e9';
            setTimeout(() => {
                status.style.backgroundColor = 'transparent';
            }, 2000);
        }
        async function sendCommand(command) {
            try {
                const response = await fetch(`api.php?command=${command}`);
                const data = await response.json();
                updateStatus(data.message);
            } catch (error) {
                console.error('Error:', error);
                updateStatus('Error sending command');
            }
        }

        async function setTempo() {
            const tempo = document.getElementById('tempo').value;
            try {
                const response = await fetch(`api.php?tempo=${tempo}`);
                const data = await response.json();
                updateStatus(data.message);
            } catch (error) {
                console.error('Error:', error);
                updateStatus('Error setting tempo');
            }
        }

        // Initialize sequence controls when page loads
        document.addEventListener('DOMContentLoaded', createSequenceControls);
    </script>
</body>
</html>

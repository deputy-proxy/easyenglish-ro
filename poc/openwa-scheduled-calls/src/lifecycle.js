export function applyConnectionState(state, connectionState, onReconnect = () => {}) {
  const wasDisconnected = state.phase === 'disconnected';
  if (connectionState === 'READY') {
    state.phase = 'authenticated';
    if (wasDisconnected) onReconnect();
  } else if (connectionState === 'DISCONNECTED') {
    state.phase = 'disconnected';
  } else if (connectionState === 'STOPPED') {
    state.phase = 'stopped';
    state.provider = null;
  } else if (connectionState === 'STARTING' || connectionState === 'AUTHENTICATING') {
    state.phase = connectionState.toLowerCase();
  }
  return state.phase;
}

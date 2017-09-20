import * as types from 'actions/actionTypes';
import { layoutSwitchActiveChat, layoutErrorMessage } from 'actions/layoutActions';

/**
 * @param {Function} dispatch
 * @param {{cmd: string}} payload
 * @param {*} state
 * @returns {*}
 */
function dispatchSocketPayload(dispatch, payload, state) {
  switch (payload.cmd) {
    case types.CMD_ERROR:
      dispatch(layoutErrorMessage(payload.error));
      break;
    case types.CMD_LOAD:
      return dispatch({
        type:         types.PMS_LOAD,
        to:           payload.to,
        conversation: payload.conversation
      });
    case types.CMD_RECEIVE:
      return dispatch({
        type:    types.PMS_RECEIVE,
        message: payload.message
      });
    case types.CMD_SENT:
      if (state.pms.isSending) {
        dispatch(layoutSwitchActiveChat(payload.message.to));
      }
      return dispatch({
        type:    types.PMS_SENT,
        message: payload.message
      });
    default:
      return console.error(`Invalid command ${payload.cmd}`);
  }

  return true;
}

/**
 * @returns {Function}
 */
export function pmsSubscribe() {
  return (dispatch, getState, api) => {
    if (!getState().user.isAuthenticated) {
      return;
    }

    dispatch({
      type: types.PMS_SUBSCRIBED
    });
    api.socket.subscribe(types.CHAN_PMS, (uri, payload) => {
      dispatchSocketPayload(dispatch, payload, getState());
    });
  };
}

/**
 * @returns {{type: string, isSending: bool}}
 */
export function pmsSending(isSending) {
  return {
    type: types.PMS_SENDING,
    isSending
  };
}

/**
 * @param {string} username
 * @param {number} numNewMessages
 * @returns {{type: string, username: string, numNewMessages: number}}
 */
export function pmsNumNewMessages(username, numNewMessages) {
  return {
    type: types.PMS_NUM_NEW_MESSAGES,
    username,
    numNewMessages
  };
}

/**
 * @param {string} username
 * @returns {Function}
 */
export function pmsLoadConversation(username) {
  return (dispatch, getState, api) => {
    if (!getState().user.isAuthenticated) {
      return;
    }

    api.socket.publish(types.CHAN_PMS, {
      cmd: types.CMD_LOAD,
      username
    });
  };
}

/**
 *
 * @param {string} to
 * @param {string} message
 * @returns {Function}
 */
export function pmsSend(to, message) {
  return (dispatch, getState, api) => {
    const user = getState().user;
    if (!user.isAuthenticated) {
      return;
    }
    if (user.username.toLowerCase() === to.toLowerCase()) {
      dispatch(layoutErrorMessage('Cannot send a PM to yourself.'));
      return;
    }

    dispatch(pmsSending(true));
    const textColor = getState().settings.user.textColor;
    api.socket.publish(types.CHAN_PMS, {
      cmd:     types.CMD_SEND,
      message: {
        to,
        message: `[${textColor}]${message}[/#]`
      }
    });
  };
}

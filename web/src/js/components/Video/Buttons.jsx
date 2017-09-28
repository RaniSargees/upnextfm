import React from 'react';
import { connect } from 'react-redux';
import { playerTogglePlay, playerToggleMute } from 'actions/playerActions';
import { formatSeconds } from 'utils/media';
import eventDispatcher from 'utils/events';
import IconButton from 'material-ui/IconButton';
import MuteIcon from 'components/Icons/MuteIcon';
import PlayIcon from 'components/Icons/PlayIcon';
import Icon from 'components/Icon';

class Buttons extends React.PureComponent {
  constructor(props) {
    super(props);
    this.state = {
      time: 0
    };
    this.offTime = null;
  }

  componentDidMount() {
    this.offTime = eventDispatcher.on('player.time', (e) => {
      this.setState({ time: e.value });
    });
  }

  componentWillUnmount() {
    this.offTime();
  }

  handleClickMute = () => {
    this.props.dispatch(playerToggleMute());
  };

  handleClickPlay = () => {
    this.props.dispatch(playerTogglePlay());
  };

  render() {
    const { player } = this.props;
    const { time } = this.state;

    return (
      <div className="up-room-video__buttons">
        <IconButton onClick={this.handleClickMute}>
          <MuteIcon isMuted={player.isMuted} />
        </IconButton>
        <IconButton title="Vote Skip">
          <Icon name="skip_next" />
        </IconButton>
        <div className="up-room-video__clock">
          {formatSeconds(time)} / {formatSeconds(player.duration)}
        </div>
      </div>
    );
  }
}

function mapStateToProps(state) {
  return Object.assign({
    player: state.player
  });
}

export default connect(mapStateToProps)(Buttons);

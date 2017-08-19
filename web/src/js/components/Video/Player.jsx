import React from 'react';
import { connect } from 'react-redux';
import YouTube from 'react-youtube';

class Player extends React.Component {
  render() {
    const { videoID } = this.props;
    const opts = {
      width:      '100%',
      playerVars: {
        autoplay: 1
      }
    };

    return (
      <YouTube videoId={videoID} opts={opts} className="up-room-video__player" />
    );
  }
}

function mapStateToProps(state) {
  return Object.assign({}, state.playlist);
}

export default connect(mapStateToProps)(Player);

// stdout: Start
// stdout: Running
// stdout: Running
// stdout: Paused
// stdout: Running
// stdout: Done
// exit: 0

enum State {
    Start,
    Running,
    Paused,
    Done,
}

fn main() {
    let mut state = State::Start;
    let mut steps: i32 = 0;
    let mut did_pause: bool = false;
    loop {
        match state {
            State::Start => {
                println!("Start");
                state = State::Running;
                steps = 0;
            }
            State::Running => {
                println!("Running");
                steps = steps + 1;
                if did_pause {
                    state = State::Done;
                } else if steps >= 2 {
                    state = State::Paused;
                    did_pause = true;
                } else if steps >= 4 {
                    state = State::Done;
                } else {
                    state = State::Running;
                }
            }
            State::Paused => {
                println!("Paused");
                state = State::Running;
            }
            State::Done => {
                println!("Done");
                break;
            }
        }
        if steps >= 4 {
            state = State::Done;
        }
    }
    exit(0);
}

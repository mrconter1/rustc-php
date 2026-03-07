// exit: 0
// stdout: no
enum Flag {
    On,
    Off,
}

fn main() {
    let f = Flag::Off;
    if let Flag::On = f {
        println!("yes");
    } else {
        println!("no");
    }
}

// exit: 0
// stdout: 1
// stdout: 3
// stdout: 5
// stdout: 7
// stdout: 9

fn main() {
    for i in 0..10 {
        if i % 2 == 0 {
            continue;
        }
        println!("{}", i);
    }
}

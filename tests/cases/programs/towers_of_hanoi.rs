// stdout: Move disk 1 from A to C
// stdout: Move disk 2 from A to B
// stdout: Move disk 1 from C to B
// stdout: Move disk 3 from A to C
// stdout: Move disk 1 from B to A
// stdout: Move disk 2 from B to C
// stdout: Move disk 1 from A to C
fn hanoi(n: i32, from: i32, to: i32, aux: i32) {
    if n == 0 {
        return;
    }
    hanoi(n - 1, from, aux, to);
    println!("Move disk {} from {} to {}", n, peg_name(from), peg_name(to));
    hanoi(n - 1, aux, to, from);
}

fn peg_name(p: i32) -> String {
    if p == 1 {
        return String::from("A");
    }
    if p == 2 {
        return String::from("B");
    }
    String::from("C")
}

fn main() {
    hanoi(3, 1, 3, 2);
}
